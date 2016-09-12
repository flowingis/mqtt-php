<?php

namespace MQTTv311;

use Psr\Log\NullLogger;

class SubscriptionEnginesTest extends \PHPUnit_Framework_TestCase
{
    public function testTopicMatches()
    {
        $this->assertTrue(Topic::topicMatches('Topic', 'Topic'));
        $this->assertTrue(Topic::topicMatches('Topic/A', 'Topic/A'));
        $this->assertTrue(Topic::topicMatches('+', 'Topic'));
        $this->assertTrue(Topic::topicMatches('+/+', 'Topic/SubTopic'));
        $this->assertTrue(Topic::topicMatches('Topic/+', 'Topic/SubTopic'));
        $this->assertTrue(Topic::topicMatches('Topic/#', 'Topic/SubTopic'));
        $this->assertTrue(Topic::topicMatches('Topic/#', 'Topic/SubTopic/SubSubTopic'));
        $this->assertTrue(Topic::topicMatches('Topic/+/Topic', 'Topic/innerPlus/Topic'));
        $this->assertTrue(Topic::topicMatches('#', 'Topic'));
        $this->assertTrue(Topic::topicMatches('#', 'Topic/SubTopic/SubSubTopic'));
    }

    public function testIsValidTopicName()
    {
        $this->assertTrue(Topic::isValidTopicName('Topic'));
        $this->assertTrue(Topic::isValidTopicName('Topic/A'));
        $this->assertTrue(Topic::isValidTopicName('+'));
        $this->assertTrue(Topic::isValidTopicName('+/+'));
        $this->assertTrue(Topic::isValidTopicName('Topic/+'));
        $this->assertTrue(Topic::isValidTopicName('Topic/#'));
        $this->assertTrue(Topic::isValidTopicName('_(*é'));
        $this->assertTrue(Topic::isValidTopicName('#'));
        $this->assertFalse(Topic::isValidTopicName('_(*é#'));
    }

    public function retainedTopics()
    {
        return [
            [[], 'TopicNone'],
            [[1 => 'Topic/A'], 'Topic/A'],
            [[4 => 'Topic/A/C'], 'Topic/A/C'],
            [['Topic'], '+'],
            [[1 => 'Topic/A', 'Topic/B', 'TopicA/A'], '+/+'],
            [[4 => 'Topic/A/C'], '+/+/+'],
            [[1 => 'Topic/A', 'Topic/B'], 'Topic/+'],
            [[4 => 'Topic/A/C'], 'Topic/+/C'],
            [['Topic', 'Topic/A', 'Topic/B', 4 => 'Topic/A/C'], 'Topic/#'],
            [['Topic', 'Topic/A', 'Topic/B', 'TopicA/A', 'Topic/A/C'], '#']
        ];
    }

    /**
     * @dataProvider retainedTopics
     */
    public function testRetainMessages($expected, $topicToCheck)
    {
        $se = new SubscriptionEngine();
        $se->setRetained('Topic', '1', 1);
        $se->setRetained('Topic/A', '1', 1);
        $se->setRetained('Topic/B', '1', 1);
        $se->setRetained('TopicA/A', '1', 1);
        $se->setRetained('Topic/A/C', '1', 1);

        $this->assertEquals($expected, $se->getRetainedTopics($topicToCheck)->toArray());

        /** @todo test getRetained($aTopic) */
    }

    public function testSubscribeAndUnsubscribe()
    {
        $se = new SubscriptionEngine();
        $se->setLogger(new NullLogger());
        $se->subscribe(97, 'topic1', 0);
        $se->subscribe(98, 'topic2', 0);
        $se->subscribe(99, 'topic1', 0);
        $se->subscribe(99, '$topic1', 0);
        $this->assertCount(2, $se->getSubscriptions('topic1'));
        $this->assertCount(1, $se->getSubscriptions('topic2'));
        $this->assertEquals([97, 99], $se->getSubscribers('topic1'));
        $this->assertEquals([97, 99], $se->getSubscribers('topic1'));

        $s1 = new Subscription(97, 'topic1', 0);
        $s2 = new Subscription(99, 'topic1', 0);

        $this->assertEquals([$s1, 2 => $s2], $se->getSubscriptions('topic1')->toArray());
        $this->assertEquals([2 => $s2], $se->getSubscriptions('topic1', 99)->toArray());

        $se->unsubscribe(99, 'topic1');
        $this->assertEquals([$s1], $se->getSubscriptions('topic1')->toArray());
        $this->assertEquals([], $se->getSubscriptions('topic1', 99)->toArray());
        $this->assertEquals([97], $se->getSubscribers('topic1'));
    }

    public function testQoS()
    {
        $se = new SubscriptionEngine();
        $se->setLogger(new NullLogger());
        $se->subscribe(97, 'topic1', 1);
        $se->subscribe(97, 'topic2', 2);
        $se->subscribe(98, 'topic2', 1);

        $this->assertEquals(1, $se->qosOf(97, 'topic1'));
        $this->assertEquals(2, $se->qosOf(97, 'topic2'));
        $this->assertEquals(1, $se->qosOf(98, 'topic2'));
    }

    public function testClearSubscriptions()
    {
        $se = new SubscriptionEngine();
        $se->setLogger(new NullLogger());
        $se->subscribe(97, 'topic1', 1);
        $se->subscribe(98, 'topic1', 2);
        $se->subscribe(98, 'topic2', 1);

        $this->assertCount(2, $se->getSubscriptions('topic1'));
        $se->clearSubscriptions(98);
        $this->assertCount(1, $se->getSubscriptions('topic1'));
        $this->assertCount(1, $se->getSubscriptions('topic1'));
    }
}
