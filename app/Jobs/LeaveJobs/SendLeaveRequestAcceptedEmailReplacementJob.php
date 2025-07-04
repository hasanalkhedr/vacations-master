<?php

namespace App\Jobs\LeaveJobs;

use App\Mail\LeaveMails\SendLeaveRequestAcceptedEmail;
use App\Mail\LeaveMails\SendLeaveRequestAcceptedEmailReplacement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendLeaveRequestAcceptedEmailReplacementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $substitute_employee, $from, $to, $employee;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($substitute_employee, $from, $to, $employee)
    {
        $this->substitute_employee= $substitute_employee;
        $this->from= $from;
        $this->to= $to;
        $this->employee= $employee;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $email = new SendLeaveRequestAcceptedEmailReplacement($this->from, $this->to, $this->employee);
        Mail::to($this->substitute_employee->email)->send($email);
    }
}
