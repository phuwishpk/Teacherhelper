<?php

namespace App\Jobs;

use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleApiException;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomGoogleTopic;
use App\Models\GoogleAccount;
use App\Models\Unit;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A unit was renamed (DESIGN §30.1): the Classroom topics made for it, in
 * every linked course, get the new name, through the account of the
 * teacher who linked each course.
 */
class RenameGoogleTopicJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 40;

    public function __construct(public readonly int $unitId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->unitId;
    }

    public function handle(GoogleAccessTokens $tokens): void
    {
        $unit = Unit::query()->find($this->unitId);
        if ($unit === null) {
            return;
        }
        $name = mb_substr(trim((string) $unit->title), 0, 100);
        $transient = false;
        foreach (ClassroomGoogleTopic::query()->where('unit_id', $unit->id)->where('name', '!=', $name)->get() as $topic) {
            $ownerId = ClassroomGoogleLink::query()->where('course_id', $topic->course_id)->value('owner_user_id');
            $account = $ownerId === null ? null : GoogleAccount::query()->find((int) $ownerId);
            if ($account === null || $account->needsReconnect()) {
                continue;
            }
            try {
                GoogleApi::forAccount($account, $tokens)->renameTopic($topic->course_id, $topic->topic_id, $name);
                $topic->forceFill(['name' => $name])->save();
            } catch (GoogleApiException $e) {
                if ($e->isTransient()) {
                    $transient = true;

                    continue;
                }
                if ($e->kind === GoogleApiException::NOT_FOUND) {
                    $topic->delete(); // made again on the next send
                }
                Log::warning('google.topic_rename_failed', ['topic_id' => $topic->topic_id, 'kind' => $e->kind]);
            }
        }
        if ($transient && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
