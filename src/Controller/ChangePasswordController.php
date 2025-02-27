<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ChangePasswordController extends Controller
{
    /**
     * @Route("/profile/change-password", name="change_password")
     * @IsGranted("ROLE_USER")
     */
    public function changePasswordAction(Request $request, UserPasswordHasherInterface $passwordHasher, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ChangePasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $passwordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('user/change_password.html.twig', array(
            'form' => $form->createView(),
        ));
    }
}
