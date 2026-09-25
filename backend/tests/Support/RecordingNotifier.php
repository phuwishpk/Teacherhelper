<?php

namespace Tests\Support;

use App\Domain\Notifications\PushMessage;
use App\Domain\Notifications\PushNotifier;

/** Records every push instead of sending it: [user ids, message]. */
class RecordingNotifier extends PushNotifier
{
    /** @var list<array{0: list<int>, 1: PushMessage}> */
    public array $sent = [];

    protected function push(array $userIds, PushMessage $message): void
    {
        $this->sent[] = [array_values($userIds), $message];
    }

    /**
     * @return list<array{0: list<int>, 1: PushMessage}>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s[1]->type === $type));
    }
}
