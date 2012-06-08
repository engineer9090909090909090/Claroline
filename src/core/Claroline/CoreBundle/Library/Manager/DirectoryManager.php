<?php

namespace Claroline\CoreBundle\Library\Manager;

use Symfony\Component\Form\FormFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Doctrine\ORM\EntityManager;
use Claroline\CoreBundle\Library\Security\RightManager\RightManagerInterface;
use Claroline\CoreBundle\Entity\Resource\AbstractResource;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Entity\Resource\Directory;
use Claroline\CoreBundle\Entity\Resource\ResourceType;
use Claroline\CoreBundle\Form\DirectoryType;
use Claroline\CoreBundle\Form\SelectResourceType;

class DirectoryManager implements ResourceManagerInterface
{
    /** @var Doctrine\ORM\EntityManager */
    protected $em;
    /** @var RightManagerInterface */
    protected $rightManager;  
    /** @var FormFactory */
    protected $formFactory;
    /** @var ContainerInterface */
    protected $container;
    /** @var ResourseManager */
    protected $resourceManager;
    /** @var TwigEngine */
    protected $templating;

    public function __construct(FormFactory $formFactory, EntityManager $em, RightManagerInterface $rightManager, ContainerInterface $container, ResourceManager $resourceManager, TwigEngine $templating)
    {
        $this->em = $em;
        $this->rightManager = $rightManager;
        $this->formFactory=$formFactory;
        $this->container=$container;
        $this->resourceManager = $resourceManager;
        $this->templating = $templating;
    }
        
    public function getForm()
    {
        return $this->formFactory->create(new DirectoryType(), new Directory());
    }
    
    public function getFormPage($twigFile, $id, $type)
    {
        $form = $this->formFactory->create(new DirectoryType(), new Directory());
        $content = $this->templating->render(
            $twigFile, array('form' => $form->createView(), 'id' => $id, 'type' =>$type)
        );
        
        return $content;
    }
    
    public function add($form, $id, $user)
    {
        $directory = new Directory();
        $name = $form['name']->getData();
        $directory->setName($name);
        $this->em->persist($directory);
        $this->em->flush();
        
        return $directory;
    }
    
    public function copy($resource, $user)
    {
        $directory = new Directory();
        $directory->setName($resource->getName());
        $this->em->persist($directory);
        $this->em->flush();
        
        return $directory;
    }
    
    //different than other resourcesmanager: it must works with resource instances
    public function delete($resourceInstance)
    {
        $children = $this->em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->children($resourceInstance, true);
        {
            foreach($children as $child)
            {
                if($child->getResourceType()->getType()!='directory')
                {
                    $rsrc = $child->getResource();
                    $this->em->remove($child);
                    $rsrc->decrInstance(); 
                
                    if($rsrc->getInstanceAmount() == 0)
                    {
                        $type = $child->getResourceType();
                        $srv = $this->findResService($type);
                        $this->container->get($srv)->delete($child->getResource());
                    }
                }            
                else
                {
                    $rsrc = $child->getResource();
                    $this->em->remove($child);
                    $rsrc->decrInstance(); 
                    
                    if($rsrc->getInstanceAmount() == 0)
                    {
                        $type = $child->getResourceType();
                        $this->em->remove($rsrc);
                    }
                }
            }
        }
        
        $rsrc = $resourceInstance->getResource();
        $this->em->remove($resourceInstance);
        $rsrc->decrInstance(); 

        if($rsrc->getInstanceAmount() == 0)
        {
            $type = $resourceInstance->getResourceType();
            $this->em->remove($rsrc);
        }
        $this->em->flush();
    }
    
    public function getResourceType()
    {
        return "directory";
    }
    
    public function getDefaultAction($id)
    {
        $formResource = $this->formFactory->create(new SelectResourceType(), new ResourceType());
        $resourceInstance = $this->em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->find($id);
        $workspace = $resourceInstance->getWorkspace();
        $resourcesInstance = $this->em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceInstance')->children($resourceInstance, true);
        $resourcesType = $this->em->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findAll();
        $content = $this->templating->render(
            'ClarolineCoreBundle:Resource:index.html.twig', array('form_resource' => $formResource->createView(), 'resources' => $resourcesInstance, 'id' => $id, 'resourcesType' => $resourcesType, 'directory' => $resourceInstance, 'workspace' => $workspace));
        $response = new Response($content);
        
        return $response;
    }    
    
    public function indexAction($workspaceId, $id)
    {
        $content = $this->templating->render(
            'ClarolineCoreBundle:Directory:index.html.twig', array('id' => $id));
        $response = new Response($content);
        
        return $response;
    }
        
    public function findAll()
    {
        $resources = $this->em->getRepository('ClarolineCoreBundle:Resource\Directory')->findAll();
        
        return $resources; 
    }
    
    private function findResService($resourceType)
    {
        $services = $this->container->getParameter("resource.service.list");
        $names = array_keys($services);
        $serviceName = null;
        
        foreach($names as $name)
        {
            $type = $this->container->get($name)->getResourceType();
            
            if($type == $resourceType->getType())
            {
                $serviceName = $name;
            }
        }
        
        return $serviceName;
    }
}