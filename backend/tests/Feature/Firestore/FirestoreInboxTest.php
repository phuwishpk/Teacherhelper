<?php

namespace Tests\Feature\Firestore;

use App\Domain\Firestore\FirestoreInbox;
use App\Domain\Firestore\LiveStore;
use App\Domain\Notifications\LogNotifier;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Jobs\MarkNoticesReadJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingLiveStore;
use Tests\TestCase;

/**
 * The notification inbox (DESIGN §38 step 2): one document per recipient
 * for every push, through whichever Notifier is bound; marking read through
 * POST /me/notices/read and its queued job.
 */
class FirestoreInboxTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private RecordingLiveStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        $this->store = new RecordingLiveStore;
        $this->app->instance(LiveStore::class, $this->store);
        $this->app->forgetInstance(Notifier::class);
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    public function test_record_writes_one_notice_per_recipient_with_the_push_fields(): void
    {
        $now = CarbonImmutable::parse('2026-10-21 10:00:00', 'Asia/Bangkok');
        $message = new PushMessage(PushMessage::GRADING_DONE, 'ตรวจการบ้าน "เศษส่วน" เสร็จแล้ว', ['assignment_id' => 7]);

        (new FirestoreInbox($this->store))->record([12, 15], $message, $now);

        $this->assertSame(1, $this->store->commits, 'both recipients in one commit');
        $this->assertCount(2, $this->store->ops);
        [$a, $b] = $this->store->ops;
        $this->assertMatchesRegularExpression('#^users/u12/notices/[0-9a-z]{26}$#', $a['path']);
        $this->assertMatchesRegularExpression('#^users/u15/notices/[0-9a-z]{26}$#', $b['path']);
        $this->assertSame(basename($a['path']), basename($b['path']), 'the same notice id for every recipient of one event');
        $this->assertTrue(FirestoreInbox::isId(basename($a['path'])));
        $fields = $a['fields'];
        $this->assertTrue($fields['created_at']->equalTo($now));
        $this->assertTrue($fields['expires_at']->equalTo($now->addDays(90)));
        unset($fields['created_at'], $fields['expires_at']);
        $this->assertSame([
            'kind' => 'grading_done',
            'title' => 'Krucheck',
            'body' => 'ตรวจการบ้าน "เศษส่วน" เสร็จแล้ว',
            'data' => ['type' => 'grading_done', 'assignment_id' => '7'],
            'read' => false,
        ], $fields);
        $this->assertArrayNotHasKey('merge', $a);
    }

    public function test_record_is_a_no_op_without_firestore_or_recipients(): void
    {
        $off = new RecordingLiveStore(on: false);
        (new FirestoreInbox($off))->record([1], new PushMessage(PushMessage::APPEAL_OPENED, 'x'));
        $this->assertSame([], $off->ops);

        (new FirestoreInbox($this->store))->record([], new PushMessage(PushMessage::APPEAL_OPENED, 'x'));
        $this->assertSame([], $this->store->ops);
    }

    public function test_more_than_500_recipients_are_split_into_commits(): void
    {
        (new FirestoreInbox($this->store))->record(range(1, 501), new PushMessage(PushMessage::CLASS_POST, 'x'));
        $this->assertSame(2, $this->store->commits);
        $this->assertCount(501, $this->store->ops);
    }

    public function test_the_bound_notifier_writes_the_inbox_before_delivering(): void
    {
        config(['services.firebase.credentials' => '']); // LogNotifier
        $this->app->forgetInstance(Notifier::class);
        $notifier = $this->app->make(Notifier::class);
        $this->assertInstanceOf(LogNotifier::class, $notifier);

        $teacher = $this->makeTeacher();
        $notifier->appealsWaiting($teacher->id, 2);

        $this->assertCount(1, $this->store->ops);
        $this->assertStringStartsWith('users/u'.$teacher->id.'/notices/', $this->store->ops[0]['path']);
        $this->assertSame('appeal_opened', $this->store->ops[0]['fields']['kind']);
    }

    public function test_duplicate_recipients_get_one_notice(): void
    {
        $notifier = (new LogNotifier)->withInbox(new FirestoreInbox($this->store));
        $teacher = $this->makeTeacher();
        $method = new \ReflectionMethod($notifier, 'push');
        $method->invoke($notifier, [$teacher->id, $teacher->id, (string) $teacher->id], new PushMessage(PushMessage::APPEAL_OPENED, 'x'));
        $this->assertCount(1, $this->store->ops);
    }

    public function test_mark_read_merges_read_true_per_id(): void
    {
        $ids = [FirestoreInbox::newId(), FirestoreInbox::newId()];
        (new FirestoreInbox($this->store))->markRead(12, [...$ids, $ids[0]]);

        $this->assertCount(2, $this->store->ops);
        $this->assertSame('users/u12/notices/'.$ids[0], $this->store->ops[0]['path']);
        $this->assertSame(['read' => true], $this->store->ops[0]['fields']);
        $this->assertTrue($this->store->ops[0]['merge']);
    }

    public function test_the_read_endpoint_validates_and_queues_the_job(): void
    {
        Queue::fake();
        $teacher = $this->makeTeacher();
        $id = FirestoreInbox::newId();

        $this->asUser($teacher)->postJson('/api/v1/me/notices/read', ['ids' => [$id, $id]])
            ->assertStatus(202)->assertExactJson(['data' => ['queued' => 1]]);
        Queue::assertPushed(MarkNoticesReadJob::class, fn (MarkNoticesReadJob $job) => $job->userId === $teacher->id && $job->ids === [$id]);

        $this->asUser($teacher)->postJson('/api/v1/me/notices/read', ['ids' => []])->assertStatus(422);
        $this->asUser($teacher)->postJson('/api/v1/me/notices/read', ['ids' => ['../users/u1']])->assertStatus(422)->assertJsonValidationErrors(['ids.0']);
        $this->asUser($teacher)->postJson('/api/v1/me/notices/read', ['ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']])->assertStatus(422); // uppercase is not what we mint
        $this->asUser($teacher)->postJson('/api/v1/me/notices/read', ['ids' => array_map(fn () => FirestoreInbox::newId(), range(1, 101))])->assertStatus(422);
        Queue::assertPushed(MarkNoticesReadJob::class, 1);

        $this->asGuest()->postJson('/api/v1/me/notices/read', ['ids' => [$id]])->assertUnauthorized();
        $this->asUser($this->makeAdmin())->postJson('/api/v1/me/notices/read', ['ids' => [$id]])->assertForbidden();

        $this->enableFirestore(false);
        $this->asUser($teacher)->postJson('/api/v1/me/notices/read', ['ids' => [$id]])
            ->assertStatus(503)->assertJsonPath('code', 'firestore_not_configured');
    }

    public function test_the_job_writes_through_the_inbox(): void
    {
        $id = FirestoreInbox::newId();
        (new MarkNoticesReadJob(12, [$id]))->handle($this->app->make(FirestoreInbox::class));
        $this->assertSame(['users/u12/notices/'.$id], $this->store->paths());
        $this->assertInstanceOf(User::class, $this->makeTeacher()); // RefreshDatabase in use
    }
}
