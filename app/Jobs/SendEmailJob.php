<?php

namespace SfphpProject\app\Jobs;

use SfPhp\Queue\Job;

class SendEmailJob extends Job
{
    protected string $email;
    protected string $subject;
    protected string $message;

    public function __construct(string $email = '', string $subject = '', string $message = '')
    {
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;
    }

    public function handle(): void
    {
        // Simulate sending email
        // mail($this->email, $this->subject, $this->message);

        echo "Email sent to {$this->email}\n";
    }
}
