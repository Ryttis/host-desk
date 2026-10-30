<?php

namespace App\Controller\Admin;

use App\Entity\{Project, ProjectEnvironment, Server, User};
use App\Service\InventoryWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ArchiveController extends AbstractController
{
    #[Route('/admin/archive/{type}/{id}', name: 'inventory_archive', requirements: ['type' => 'server|project|environment', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function archive(string $type, int $id, Request $request, EntityManagerInterface $em, InventoryWriter $writer): Response
    {
        $class = match ($type) { 'server' => Server::class, 'project' => Project::class, 'environment' => ProjectEnvironment::class };
        $entity = $em->find($class, $id) ?? throw $this->createNotFoundException();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('archive:'.$type.':'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $user = $this->getUser();
            if (!$user instanceof User || !$user->isMfaConfirmed()) { throw $this->createAccessDeniedException(); }
            try {
                $writer->archive($entity, 'user:'.$user->getId().' '.$user->getUserIdentifier());
                return $this->redirectToRoute('admin');
            } catch (\DomainException $e) { $error = $e->getMessage(); }
        }
        return $this->render('admin/archive.html.twig', ['entity' => $entity, 'type' => $type, 'id' => $id, 'error' => $error], new Response(status: $error ? 422 : 200));
    }
}
