<?php

namespace App\Domain\Messaging\Channels;

use App\Models\ChannelAccount;
use App\Models\Message;

interface ChannelAdapter
{
    /** Credential keys this driver needs; the account is "not configured" while any is missing. */
    public function requiredCredentials(): array;

    /** @throws SendFailure */
    public function send(Message $message, ChannelAccount $account): SendResult;
}
