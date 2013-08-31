<?php

namespace Zoop\Shard\Test\Freeze;

use Doctrine\Common\EventSubscriber;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Zoop\Shard\Freeze\Events;
use Zoop\Shard\Manifest;
use Zoop\Shard\Test\BaseTest;
use Zoop\Shard\Test\Freeze\TestAsset\Document\Simple;
use Zoop\Shard\Test\TestAsset\User;

class FreezeTest extends BaseTest implements EventSubscriber
{
    public function setUp()
    {
        $manifest = new Manifest(
            [
                'documents' => [
                    __NAMESPACE__ . '\TestAsset\Document' => __DIR__ . '/TestAsset/Document'
                ],
                'extension_configs' => [
                    'extension.freeze' => true
                ],
                'document_manager' => 'testing.documentmanager',
                'service_manager_config' => [
                    'factories' => [
                        'testing.documentmanager' => 'Zoop\Shard\Test\TestAsset\DocumentManagerFactory',
                        'user' => function () {
                            $user = new User();
                            $user->setUsername('toby');
                            return $user;
                        }
                    ]
                ]
            ]
        );

        $this->documentManager = $manifest->getServiceManager()->get('testing.documentmanager');
        $this->freezer = $manifest->getServiceManager()->get('freezer');
    }

    public function testBasicFunction()
    {
        $documentManager = $this->documentManager;
        $testDoc = new Simple();

        $testDoc->setName('version 1');

        $documentManager->persist($testDoc);
        $documentManager->flush();
        $id = $testDoc->getId();
        $documentManager->clear();

        $repository = $documentManager->getRepository(get_class($testDoc));
        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertFalse($this->freezer->isFrozen($testDoc));

        $this->freezer->freeze($testDoc);

        $documentManager->flush();
        $documentManager->clear();
        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertTrue($this->freezer->isFrozen($testDoc));

        $testDoc->setName('version 2');

        $documentManager->flush();
        $documentManager->clear();
        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertEquals('version 1', $testDoc->getName());

        $documentManager->remove($testDoc);
        $documentManager->flush();
        $documentManager->clear();
        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertEquals('version 1', $testDoc->getName());

        $this->freezer->thaw($testDoc);

        $documentManager->flush();
        $documentManager->clear();
        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertFalse($this->freezer->isFrozen($testDoc));
    }

    public function testFilter()
    {
        $documentManager = $this->documentManager;
        $documentManager->getFilterCollection()->enable('freeze');

        $testDocA = new Simple();
        $testDocA->setName('miriam');

        $testDocB = new Simple();
        $testDocB->setName('lucy');

        $documentManager->persist($testDocA);
        $documentManager->persist($testDocB);
        $documentManager->flush();
        $ids = array($testDocA->getId(), $testDocB->getId());
        $documentManager->clear();

        list($testDocs, $docNames) = $this->getTestDocs();
        $this->assertEquals(array('lucy', 'miriam'), $docNames);

        if ($testDocs[0]->getName() == 'lucy') {
            $this->freezer->freeze($testDocs[0]);
        } else {
            $this->freezer->freeze($testDocs[1]);
        }

        $documentManager->flush();
        $documentManager->clear();

        list($testDocs, $docNames) = $this->getTestDocs();
        $this->assertEquals(array('miriam'), $docNames);

        $filter = $documentManager->getFilterCollection()->getFilter('freeze');
        $filter->onlyFrozen();
        $documentManager->clear();

        list($testDocs, $docNames) = $this->getTestDocs();
        $this->assertEquals(array('lucy'), $docNames);

        $filter->onlyNotFrozen();
        $documentManager->clear();

        list($testDocs, $docNames) = $this->getTestDocs();
        $this->assertEquals(array('miriam'), $docNames);

        $documentManager->getFilterCollection()->disable('freeze');

        $documentManager->flush();
        $documentManager->clear();

        list($testDocs, $docNames) = $this->getTestDocs();
        $this->assertEquals(array('lucy', 'miriam'), $docNames);

        if ($testDocs[0]->getName() == 'lucy') {
            $this->freezer->thaw($testDocs[0]);
        } else {
            $this->freezer->thaw($testDocs[1]);
        }

        $documentManager->getFilterCollection()->enable('freeze');

        $documentManager->flush();
        $documentManager->clear();

        list($testDocs, $docNames) = $this->getTestDocs();
        $this->assertEquals(array('lucy', 'miriam'), $docNames);
    }

    protected function getTestDocs()
    {
        $repository = $this->documentManager->getRepository('Zoop\Shard\Test\Freeze\TestAsset\Document\Simple');
        $testDocs = $repository->findAll();
        $returnDocs = array();
        $returnNames = array();
        foreach ($testDocs as $testDoc) {
            $returnDocs[] = $testDoc;
            $returnNames[] = $testDoc->getName();
        }
        sort($returnNames);
        return array($returnDocs, $returnNames);
    }

    public function testEvents()
    {
        $subscriber = $this;

        $documentManager = $this->documentManager;
        $eventManager = $documentManager->getEventManager();
        $eventManager->addEventSubscriber($subscriber);

        $testDoc = new Simple();

        $testDoc->setName('version 1');

        $documentManager->persist($testDoc);
        $documentManager->flush();
        $id = $testDoc->getId();
        $documentManager->clear();

        $calls = $this->calls;
        $this->assertFalse(isset($calls[Events::PRE_FREEZE]));
        $this->assertFalse(isset($calls[Events::POST_FREEZE]));
        $this->assertFalse(isset($calls[Events::PRE_THAW]));
        $this->assertFalse(isset($calls[Events::POST_THAW]));

        $repository = $documentManager->getRepository(get_class($testDoc));
        $testDoc = $repository->find($id);

        $this->assertFalse($this->freezer->isFrozen($testDoc));

        $this->freezer->freeze($testDoc);
        $subscriber->reset();

        $documentManager->flush();

        $calls = $this->calls;
        $this->assertTrue(isset($calls[Events::PRE_FREEZE]));
        $this->assertTrue(isset($calls[Events::POST_FREEZE]));
        $this->assertFalse(isset($calls[Events::PRE_THAW]));
        $this->assertFalse(isset($calls[Events::POST_THAW]));

        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertTrue($this->freezer->isFrozen($testDoc));

        $testDoc->setName('version 2');
        $subscriber->reset();
        $documentManager->flush();

        $calls = $this->calls;
        $this->assertTrue(isset($calls[Events::FROZEN_UPDATE_DENIED]));
        $subscriber->reset();

        $documentManager->remove($testDoc);
        $documentManager->flush();

        $calls = $this->calls;
        $this->assertTrue(isset($calls[Events::FROZEN_DELETE_DENIED]));

        $documentManager->clear();
        $testDoc = $repository->find($id);

        $this->freezer->thaw($testDoc);
        $subscriber->reset();

        $documentManager->flush();

        $calls = $this->calls;
        $this->assertFalse(isset($calls[Events::PRE_FREEZE]));
        $this->assertFalse(isset($calls[Events::POST_FREEZE]));
        $this->assertTrue(isset($calls[Events::PRE_THAW]));
        $this->assertTrue(isset($calls[Events::POST_THAW]));

        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertFalse($this->freezer->isFrozen($testDoc));

        $this->freezer->freeze($testDoc);
        $subscriber->reset();
        $subscriber->setRollbackFreeze(true);

        $documentManager->flush();

        $calls = $this->calls;
        $this->assertTrue(isset($calls[Events::PRE_FREEZE]));
        $this->assertFalse(isset($calls[Events::POST_FREEZE]));
        $this->assertFalse(isset($calls[Events::PRE_THAW]));
        $this->assertFalse(isset($calls[Events::POST_THAW]));

        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertFalse($this->freezer->isFrozen($testDoc));
        $this->freezer->freeze($testDoc);
        $subscriber->reset();
        $documentManager->flush();

        $testDoc = null;
        $testDoc = $repository->find($id);

        $this->assertTrue($this->freezer->isFrozen($testDoc));

        $this->freezer->thaw($testDoc);
        $subscriber->reset();
        $subscriber->setRollbackThaw(true);

        $documentManager->flush();

        $calls = $this->calls;
        $this->assertFalse(isset($calls[Events::PRE_FREEZE]));
        $this->assertFalse(isset($calls[Events::POST_FREEZE]));
        $this->assertTrue(isset($calls[Events::PRE_THAW]));
        $this->assertFalse(isset($calls[Events::POST_THAW]));
    }

    protected $calls = array();

    protected $rollbackFreeze = false;
    protected $rollbackThaw = false;

    public function getSubscribedEvents()
    {
        return array(
            Events::PRE_FREEZE,
            Events::POST_FREEZE,
            Events::PRE_THAW,
            Events::POST_THAW,
            Events::FROZEN_UPDATE_DENIED,
            Events::FROZEN_DELETE_DENIED
        );
    }

    public function reset()
    {
        $this->calls = array();
        $this->rollbackFreeze = false;
        $this->rollbackThaw = false;
    }

    public function preFreeze(LifecycleEventArgs $eventArgs)
    {
        $this->calls[Events::PRE_FREEZE] = $eventArgs;
        if ($this->rollbackFreeze) {
            $this->freezer->thaw($eventArgs->getDocument());
        }
    }

    public function preThaw(LifecycleEventArgs $eventArgs)
    {
        $this->calls[Events::PRE_THAW] = $eventArgs;
        if ($this->rollbackThaw) {
            $this->freezer->freeze($eventArgs->getDocument());
        }
    }

    public function getRollbackFreeze()
    {
        return $this->rollbackFreeze;
    }

    public function setRollbackFreeze($rollbackFreeze)
    {
        $this->rollbackFreeze = $rollbackFreeze;
    }

    public function getRollbackThaw()
    {
        return $this->rollbackThaw;
    }

    public function setRollbackThaw($rollbackThaw)
    {
        $this->rollbackThaw = $rollbackThaw;
    }

    public function getCalls()
    {
        return $this->calls;
    }

    public function __call($name, $arguments)
    {
        $this->calls[$name] = $arguments[0];
    }
}