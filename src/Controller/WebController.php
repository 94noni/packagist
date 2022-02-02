<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Algolia\AlgoliaSearch\SearchClient;
use App\Entity\Version;
use App\Form\Model\SearchQuery;
use App\Form\Type\SearchQueryType;
use App\Entity\Package;
use App\Entity\PhpStat;
use App\Util\Killswitch;
use Predis\Connection\ConnectionException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Predis\Client as RedisClient;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
    /**
     * @Template()
     * @Route("/", name="home")
     */
    public function indexAction(Request $req)
    {
        if ($resp = $this->checkForQueryMatch($req)) {
            return $resp;
        }

        return ['page' => 'home'];
    }

    /**
     * Rendered by views/Web/search_section.html.twig
     */
    public function searchFormAction(Request $req)
    {
        $form = $this->createForm(SearchQueryType::class, new SearchQuery(), [
            'action' => $this->generateUrl('search.ajax'),
        ]);

        $filteredOrderBys = $this->getFilteredOrderedBys($req);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $form->handleRequest($req);

        return $this->render('web/search_form.html.twig', [
            'searchQuery' => $req->query->all()['search_query']['query'] ?? '',
        ]);
    }

    private function checkForQueryMatch(Request $req)
    {
        $q = $req->query->get('query');
        if ($q) {
            $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => $q]);
            if ($package) {
                return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
            }
        }
    }

    /**
     * @Route("/search/", name="search.ajax", methods={"GET"})
     * @Route("/search.{_format}", requirements={"_format"="(html|json)"}, name="search", defaults={"_format"="html"}, methods={"GET"})
     */
    public function searchAction(Request $req, SearchClient $algolia, string $algoliaIndexName)
    {
        if ($resp = $this->checkForQueryMatch($req)) {
            return $resp;
        }

        if ($req->getRequestFormat() !== 'json') {
            return $this->render('web/search.html.twig', [
                'packages' => [],
            ]);
        }

        $blockList = ['2400:6180:100:d0::83b:b001', '34.235.38.170'];
        if (in_array($req->getClientIp(), $blockList, true)) {
            return (new JsonResponse([
                'error' => 'Too many requests, reach out to contact@packagist.org'
            ], 400))->setCallback($req->query->get('callback'));
        }

        $typeFilter = str_replace('%type%', '', $req->query->get('type'));
        $tagsFilter = $req->query->get('tags');

        $filteredOrderBys = $this->getFilteredOrderedBys($req);

        $this->computeSearchQuery($req, $filteredOrderBys);

        if (!$req->query->has('search_query') && !$typeFilter && !$tagsFilter) {
            return (new JsonResponse([
                'error' => 'Missing search query, example: ?q=example'
            ], 400))->setCallback($req->query->get('callback'));
        }

        $searchQuery = new SearchQuery();
        $form = $this->createForm(SearchQueryType::class, $searchQuery);

        $index = $algolia->initIndex($algoliaIndexName);
        $query = '';
        $queryParams = [];

        // filter by type
        if ($typeFilter) {
            $queryParams['filters'][] = 'type:'.$typeFilter;
        }

        // filter by tags
        if ($tagsFilter) {
            $tags = [];
            foreach ((array) $tagsFilter as $tag) {
                $tag = strtr($tag, '-', ' ');
                $tags[] = 'tags:"'.$tag.'"';
                if (false !== strpos($tag, ' ')) {
                    $tags[] = 'tags:"'.strtr($tag, ' ', '-').'"';
                }
            }
            $queryParams['filters'][] = '(' . implode(' OR ', $tags) . ')';
        }

        if (!empty($filteredOrderBys)) {
            return (new JsonResponse([
                'status' => 'error',
                'message' => 'Search sorting is not available anymore',
            ], 400))->setCallback($req->query->get('callback'));
        }

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $query = $searchQuery->query;
        }

        $perPage = max(1, (int) $req->query->getInt('per_page', 15));
        if ($perPage <= 0 || $perPage > 100) {
            return (new JsonResponse([
                'status' => 'error',
                'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
            ], 400))->setCallback($req->query->get('callback'));
        }

        if (isset($queryParams['filters'])) {
            $queryParams['filters'] = implode(' AND ', $queryParams['filters']);
        }
        $queryParams['hitsPerPage'] = $perPage;
        $queryParams['page'] = max(1, $req->query->getInt('page', 1)) - 1;

        try {
            $results = $index->search($query, $queryParams);
        } catch (\Throwable $e) {
            return (new JsonResponse([
                'status' => 'error',
                'message' => 'Could not connect to the search server',
            ], 500))->setCallback($req->query->get('callback'));
        }

        $result = [
            'results' => [],
            'total' => $results['nbHits'],
        ];

        foreach ($results['hits'] as $package) {
            if (ctype_digit((string) $package['id'])) {
                $url = $this->generateUrl('view_package', ['name' => $package['name']], UrlGeneratorInterface::ABSOLUTE_URL);
            } else {
                $url = $this->generateUrl('view_providers', ['name' => $package['name']], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            $row = [
                'name' => $package['name'],
                'description' => $package['description'] ?: '',
                'url' => $url,
                'repository' => $package['repository'],
            ];
            if (ctype_digit((string) $package['id'])) {
                $row['downloads'] = $package['meta']['downloads'];
                $row['favers'] = $package['meta']['favers'];
            } else {
                $row['virtual'] = true;
            }
            if (!empty($package['abandoned'])) {
                $row['abandoned'] = isset($package['replacementPackage']) && $package['replacementPackage'] !== '' ? $package['replacementPackage'] : true;
            }
            $result['results'][] = $row;
        }

        if ($results['nbPages'] > $results['page'] + 1) {
            $params = [
                '_format' => 'json',
                'q' => $searchQuery->query,
                'page' => $results['page'] + 2,
            ];
            if ($tagsFilter) {
                $params['tags'] = (array) $tagsFilter;
            }
            if ($typeFilter) {
                $params['type'] = $typeFilter;
            }
            if ($perPage !== 15) {
                $params['per_page'] = $perPage;
            }
            $result['next'] = $this->generateUrl('search', $params, UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $response = (new JsonResponse($result))->setCallback($req->query->get('callback'));
        $response->setSharedMaxAge(300);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * @Route("/statistics", name="stats")
     */
    public function statsAction(RedisClient $redis)
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $packages = $this->getEM()->getRepository(Package::class)->getCountByYearMonth();
        $versions = $this->getEM()->getRepository(Version::class)->getCountByYearMonth();

        $chart = ['versions' => [], 'packages' => [], 'months' => []];

        // prepare x axis
        $date = new \DateTime($packages[0]['year'] . '-' . $packages[0]['month'] . '-01');
        $now = new \DateTime;
        while ($date < $now) {
            $chart['months'][] = $month = $date->format('Y-m');
            $date->modify('+1month');
        }

        // prepare data
        $count = 0;
        foreach ($packages as $dataPoint) {
            $count += $dataPoint['count'];
            $chart['packages'][$dataPoint['year'] . '-' . str_pad($dataPoint['month'], 2, '0', STR_PAD_LEFT)] = $count;
        }

        $count = 0;
        foreach ($versions as $dataPoint) {
            $yearMonth = $dataPoint['year'] . '-' . str_pad($dataPoint['month'], 2, '0', STR_PAD_LEFT);
            $count += $dataPoint['count'];
            if (in_array($yearMonth, $chart['months'])) {
                $chart['versions'][$yearMonth] = $count;
            }
        }

        // fill gaps at the end of the chart
        if (count($chart['months']) > count($chart['packages'])) {
            $chart['packages'] += array_fill(0, count($chart['months']) - count($chart['packages']), !empty($chart['packages']) ? max($chart['packages']) : 0);
        }
        if (count($chart['months']) > count($chart['versions'])) {
            $chart['versions'] += array_fill(0, count($chart['months']) - count($chart['versions']), !empty($chart['versions']) ? max($chart['versions']) : 0);
        }

        $downloadsStartDate = '2012-04-13';

        try {
            $downloads = $redis->get('downloads') ?: 0;

            $date = new \DateTime($downloadsStartDate.' 00:00:00');
            $today = new \DateTime('today 00:00:00');
            $dailyGraphStart = new \DateTime('-30days 00:00:00'); // 30 days before today

            $dlChart = $dlChartMonthly = [];
            while ($date <= $today) {
                if ($date > $dailyGraphStart) {
                    $dlChart[$date->format('Y-m-d')] = 'downloads:'.$date->format('Ymd');
                }
                $dlChartMonthly[$date->format('Y-m')] = 'downloads:'.$date->format('Ym');
                $date->modify('+1day');
            }

            $dlChart = [
                'labels' => array_keys($dlChart),
                'values' => $redis->mget(array_values($dlChart))
            ];
            $dlChartMonthly = [
                'labels' => array_keys($dlChartMonthly),
                'values' => $redis->mget(array_values($dlChartMonthly))
            ];
        } catch (ConnectionException $e) {
            $downloads = 'N/A';
            $dlChart = $dlChartMonthly = null;
        }

        return $this->render('web/stats.html.twig', [
            'chart' => $chart,
            'packages' => !empty($chart['packages']) ? max($chart['packages']) : 0,
            'versions' => !empty($chart['versions']) ? max($chart['versions']) : 0,
            'downloads' => $downloads,
            'downloadsChart' => $dlChart,
            'maxDailyDownloads' => !empty($dlChart) ? max($dlChart['values']) : null,
            'downloadsChartMonthly' => $dlChartMonthly,
            'maxMonthlyDownloads' => !empty($dlChartMonthly) ? max($dlChartMonthly['values']) : null,
            'downloadsStartDate' => $downloadsStartDate,
        ]);
    }

    /**
     * @Route("/php-statistics", name="php_stats")
     */
    public function phpStatsAction(): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $versions = [
            '5.3',
            '5.4',
            '5.5',
            '5.6',
            '7.0',
            '7.1',
            '7.2',
            '7.3',
            '7.4',
            '8.0',
            '8.1',
            '8.2',
            '8.3',
            '8.4',
            // 'hhvm', // honorable mention here but excluded as it's so low (below 0.00%) it's irrelevant
        ];

        $dailyData = $this->getEM()->getRepository(PhpStat::class)->getGlobalChartData($versions, 'days', 'php');
        $monthlyData = $this->getEM()->getRepository(PhpStat::class)->getGlobalChartData($versions, 'months', 'php');

        $resp = $this->render('web/php_stats.html.twig', [
            'dailyData' => $dailyData,
            'monthlyData' => $monthlyData,
        ]);
        $resp->setSharedMaxAge(1800);
        $resp->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $resp;
    }

    /**
     * @Route("/statistics.json", name="stats_json", defaults={"_format"="json"}, methods={"GET"})
     */
    public function statsTotalsAction(RedisClient $redis)
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $downloads = (int) ($redis->get('downloads') ?: 0);
        $packages = $this->getEM()->getRepository(Package::class)->getTotal();
        $versions = $this->getEM()->getRepository(Version::class)->getTotal();

        $totals = [
            'downloads' => $downloads,
            'packages' => $packages,
            'versions' => $versions,
        ];

        return new JsonResponse(['totals' => $totals], 200);
    }

    /**
     * @return array<array{sort: 'downloads'|'favers', order: 'asc'|'desc'}>
     */
    protected function getFilteredOrderedBys(Request $req): array
    {
        $orderBys = $req->query->all()['orderBys'] ?? [];
        if (!$orderBys) {
            $orderBys = $req->query->all()['search_query']['orderBys'] ?? [];
        }

        if ($orderBys) {
            $allowedSorts = [
                'downloads' => 1,
                'favers' => 1
            ];

            $allowedOrders = [
                'asc' => 1,
                'desc' => 1,
            ];

            $filteredOrderBys = [];

            foreach ((array) $orderBys as $orderBy) {
                if (
                    is_array($orderBy)
                    && isset($orderBy['sort'])
                    && isset($allowedSorts[$orderBy['sort']])
                    && isset($orderBy['order'])
                    && isset($allowedOrders[$orderBy['order']])
                ) {
                    $filteredOrderBys[] = ['order' => $orderBy['order'], 'sort' => $orderBy['sort']];
                }
            }
        } else {
            $filteredOrderBys = [];
        }

        return $filteredOrderBys;
    }

    /**
     * @param Request $req
     * @param array $filteredOrderBys
     */
    private function computeSearchQuery(Request $req, array $filteredOrderBys)
    {
        // transform q=search shortcut
        if ($req->query->has('q') || $req->query->has('orderBys')) {
            $searchQuery = [];

            $q = $req->query->get('q');

            if ($q !== null) {
                $searchQuery['query'] = $q;
            }

            if (!empty($filteredOrderBys)) {
                $searchQuery['orderBys'] = $filteredOrderBys;
            }

            $req->query->set(
                'search_query',
                $searchQuery
            );
        }
    }
}
