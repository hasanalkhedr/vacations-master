<?php

namespace App\Services;

use App\Models\LeaveConfig;
use DateTime;
use App\Helpers\Helper;
use App\Jobs\LeaveJobs\SendLeaveRequestAcceptedEmailJob;
use App\Jobs\LeaveJobs\SendLeaveRequestAcceptedEmailReplacementJob;
use App\Jobs\LeaveJobs\SendLeaveRequestCanceledEmailJob;
use App\Jobs\LeaveJobs\SendLeaveRequestIncomingEmailJob;
use App\Jobs\LeaveJobs\SendLeaveRequestIncomingEmailReplacementJob;
use App\Jobs\LeaveJobs\SendLeaveRequestRejectedEmailJob;
use App\Jobs\LeaveJobs\SendLeaveRequestRejectedEmailReplacementJob;
use App\Models\Confessionnel;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

class LeaveService
{
    const PENDING_STATUS = 0;
    const ACCEPTED_STATUS = 1;
    const REJECTED_STATUS = 2;
    const DAY_TO_MINUTES = 450;

    public function sendEmailToInvolvedEmployees($leave, $processing_officers = NULL, $substitute_employee = NULL, $delete = false)
    {
        if ($delete) {
            if ($substitute_employee && $substitute_employee->can_receive_emails) {
                dispatch(new SendLeaveRequestCanceledEmailJob($substitute_employee));
            }
            foreach ($processing_officers as $processing_officer) {
                if ($processing_officer->can_receive_emails) {
                    dispatch(new SendLeaveRequestCanceledEmailJob($processing_officer));
                }
            }
        } else {
            if ($leave->leave_status == self::ACCEPTED_STATUS) {
                $employee = Employee::where('id', $leave->employee_id)->first();
                if ($employee->can_receive_emails) {
                    dispatch(new SendLeaveRequestAcceptedEmailJob($employee));
                }
                if ($substitute_employee && $substitute_employee->can_receive_emails) {
                    dispatch(new SendLeaveRequestAcceptedEmailReplacementJob($substitute_employee, $leave->from, $leave->to, $leave->employee));
                }
            } elseif ($leave->leave_status == self::REJECTED_STATUS) {
                $employee = Employee::where('id', $leave->employee_id)->first();
                if ($employee->can_receive_emails) {
                    dispatch(new SendLeaveRequestRejectedEmailJob($employee));
                }
                if ($substitute_employee && $substitute_employee->can_receive_emails) {
                    dispatch(new SendLeaveRequestRejectedEmailReplacementJob($substitute_employee));
                }
            } else {
                foreach ($processing_officers as $processing_officer) {
                    if ($processing_officer->can_receive_emails) {
                        dispatch(new SendLeaveRequestIncomingEmailJob($processing_officer));
                    }
                }
                if ($substitute_employee && $substitute_employee->can_receive_emails) {
                    dispatch(new SendLeaveRequestIncomingEmailReplacementJob($substitute_employee, $leave->from, $leave->to, $leave->employee));
                }
            }
        }
    }

    public function checkProcessingOfficerandElevateRequest($leave)
    {
        $employee = $leave->employee;
        $processing_officer_role = $leave->processing_officer_role;
        $role = Role::findById($processing_officer_role);
        switch ($role->name) {
            case ('human_resource'):
                if (auth()->user()->hasRole('sg')) {
                    $role_sg = Role::findByName('sg');
                    $leave->processing_officer_role = $role_sg->id;
                    $this->acceptLeave($leave);
                    $processing_officers = NULL;
                    break;
                } else {
                    $role = Role::findByName('sg');
                    $leave->processing_officer_role = $role->id;
                    $head = Employee::role('head')->get();
                    $processing_officers = Employee::role('sg')->get()->concat($head)->all();
                }
                break;
            case ('employee'):
                if (auth()->user()->hasRole(['sg', 'head'])) {
                    $role_sg = Role::findByName('sg');
                    $leave->processing_officer_role = $role_sg->id;
                    $this->acceptLeave($leave);
                    $processing_officers = NULL;
                    break;
                }
                $role = Role::findByName('human_resource');
                $leave->processing_officer_role = $role->id;
                $processing_officers = Employee::role('human_resource')->get();
                break;
            case ('sg'):
                $this->acceptLeave($leave);
                $processing_officers = NULL;
                break;
        }
        $leave->save();
        if ($leave->leave_status == self::ACCEPTED_STATUS) {
            $this->sendEmailToInvolvedEmployees($leave, $processing_officers, $leave->substitute_employee);
        } else {
            $this->sendEmailToInvolvedEmployees($leave, $processing_officers);
        }
    }

    public function rejectLeaveRequest($request, $leave)
    {
        $leave->leave_status = self::REJECTED_STATUS;
        if ($request['cancellation_reason']) {
            $leave->cancellation_reason = $request['cancellation_reason'];
        }
        $leave->rejected_by = auth()->user()->id;
        $leave->save();
        $this->sendEmailToInvolvedEmployees($leave, NULL, $leave->substitute_employee);
    }

    public function acceptLeave($leave)
    {
        $this->updateNbOfDaysOff($leave);
        $recoveryLeave = LeaveType::where('name', 'recovery')->first();

        if ($leave->leave_type_id == $recoveryLeave->id) {
            $this->subtractOvertimeMinutes($leave);
        }
        $leave->leave_status = self::ACCEPTED_STATUS;
        $leave->save();
    }

    public function updateNbOfDaysOff($leave)
    {
        if ($this->isLeaveNonDeductible($leave)) {
            return;
        }
        $employee = $leave->employee;
        if ($leave->use_confessionnels) {
            if (($employee->confessionnels - 1) < 0)
                return;
            $employee->confessionnels = $employee->confessionnels - 1;
        } else {
            $nb_of_days_off = $this->findNbofDaysOff($leave);
            if ($leave->mix_of_leaves) {
                $nb_of_days_off_confessionnels = $this->findNbofDaysOffConfessionnels($leave);
                if (($employee->confessionnels - $nb_of_days_off_confessionnels) < 0)
                    return;
                $employee->confessionnels = $employee->confessionnels - $nb_of_days_off_confessionnels;
            }
            $employee->nb_of_days = $employee->nb_of_days - $nb_of_days_off;

            $expireDate = Carbon::create(null, LeaveConfig::find('expire_month')->value, LeaveConfig::find('expire_day')->value);
            if (now()->isBefore($expireDate) && $employee->prev_leaves > 0) {
                $employee->prev_leaves = $employee->prev_leaves < $nb_of_days_off ? 0 : $employee->prev_leaves - $nb_of_days_off;
            }
        }

        $employee->save();
    }

    public function isLeaveNonDeductible($leave)
    {
        $leave_type_id = $leave->leave_type_id;
        $leave_types_no_deduction_array = [];
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'recovery')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'assignment')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'training')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'sick leave')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'bereavement leave')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'maternity leave')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'paternity leave')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'marriage leave')->first()->id;
        $leave_types_no_deduction_array[] = LeaveType::where('name', 'remote work')->first()->id;
        return in_array($leave_type_id, $leave_types_no_deduction_array);
    }

    public function getDisabledDates($employee)
    {
        $leaves = Leave::where('employee_id', $employee->id)->get();
        $disabled_dates = [];
        foreach ($leaves as $leave) {
            $period = CarbonPeriod::create($leave->from, $leave->to);
            // Iterate over the period
            foreach ($period as $date) {
                if (!in_array($date->toDateString(), $disabled_dates))
                    $disabled_dates[] = $date->toDateString();
            }
        }

        return $disabled_dates;
    }

    public function getConfessionnelDates()
    {
        $confessionnels = Confessionnel::all();
        $confessionnel_dates = [];
        foreach ($confessionnels as $confessionnel) {
            $confessionnel_dates[] = $confessionnel->date;
        }
        return $confessionnel_dates;
    }

    public function isConfessionnel($date)
    {
        $confessionnels = $this->getConfessionnelDates();
        return (in_array($date, $confessionnels));
    }

    public function getConfessionnels()
    {
        $confessionnels = Confessionnel::whereNotIn('date', Leave::where('employee_id', auth()->user()->id)->where('use_confessionnels', true)->get('from'))->get();
        $mix_leaves = Leave::where('employee_id', auth()->user()->id)->where('mix_of_leaves', true)->get();
        $mix_confessionnels = [];
        foreach ($mix_leaves as $mix_leave) {
            $period = CarbonPeriod::create($mix_leave->from, $mix_leave->to);
            // Iterate over the period
            foreach ($period as $date) {
                if (!in_array($date->toDateString(), $mix_confessionnels))
                    $mix_confessionnels[] = $date->toDateString();
            }
        }
        $confessionnel_dates = [];
        foreach ($confessionnels as $confessionnel) {
            if (!in_array($confessionnel->date, $mix_confessionnels))
                $confessionnel_dates[] = $confessionnel->date;
        }
        return $confessionnel_dates;
    }

    public function subtractOvertimeMinutes(Leave $leave)
    {
        $days = $this->findNbofDaysOff($leave);
        $minutes = $days * self::DAY_TO_MINUTES;
        $employee = $leave->employee;
        $employee->overtime_minutes -= $minutes;
        $employee->save();
    }

    public function findNbofDaysOff($leave, $fromDate = null, $toDate = null)
    {
        $helper = new Helper();

        $leaveFromDate = $leave->from;
        $leaveToDate = $leave->to;

        // Check if provided dates are within the leave's date range
        if ($fromDate !== null && $fromDate > $leaveToDate) {
            return 0;
        }

        if ($toDate !== null && $toDate < $leaveFromDate) {
            return 0;
        }

        // Create a date range based on the provided or leave's start and end dates
        $startDate = ($fromDate !== null && $fromDate >= $leaveFromDate) ? $fromDate : $leaveFromDate;
        $endDate = ($toDate !== null && $toDate <= $leaveToDate) ? $toDate : $leaveToDate;

        $period = CarbonPeriod::create($startDate, $endDate);
        $nb_of_days_off = 0;
        $disabled_dates = unserialize($leave->disabled_dates);

        $employee = $leave->employee;

        foreach ($period as $date) {
            $date = $date->toDateString();
            if (!$helper->isWeekend($date, $leave->employee) && !in_array($date, $disabled_dates) && !$helper->isHoliday($date) && (!($this->isConfessionnel($date) && $leave->leave_type->name !== 'sick leave') || ($this->isConfessionnel($date) && !$employee->confessionnels))) {
                $nb_of_days_off = $nb_of_days_off + 1;
            }
        }

        $leave_duration_name = $leave->leave_duration->name;

        if ($leave_duration_name == "Half Day AM" || $leave_duration_name == "Half Day PM") {
            $nb_of_days_off = $nb_of_days_off / 2;
        }

        return $nb_of_days_off;
    }


    public function findNbofDaysOffConfessionnels($leave)
    {
        $helper = new Helper();
        $period = CarbonPeriod::create($leave->from, $leave->to);
        $nb_of_days_off_confessionnels = 0;
        $disabled_dates = unserialize($leave->disabled_dates);
        foreach ($period as $date) {
            $date = $date->toDateString();
            if (!$helper->isWeekend($date, $leave->employee) && !in_array($date, $disabled_dates) && !$helper->isHoliday($date) && $this->isConfessionnel($date)) {
                $nb_of_days_off_confessionnels = $nb_of_days_off_confessionnels + 1;
            }
        }
        $leave_duration_name = $leave->leave_duration->name;
        if ($leave_duration_name == "Half Day AM" || $leave_duration_name == "Half Day PM") {
            $nb_of_days_off_confessionnels = $nb_of_days_off_confessionnels / 2;
        }
        return $nb_of_days_off_confessionnels;
    }

    public function fetchLeaves($employee_id, $filtered_leave_types_ids, $from_date, $to_date)
    {
        $leaves = Leave::where('employee_id', $employee_id)
            ->where('leave_status', self::ACCEPTED_STATUS)
            ->whereIn('leave_type_id', $filtered_leave_types_ids)
            ->where(function ($query) use ($from_date, $to_date) {
                $query->where(function ($query) use ($from_date, $to_date) {
                    $query->whereDate('from', '>=', $from_date)->whereDate('from', '<=', $to_date);
                })
                    ->orWhere(function ($query) use ($from_date, $to_date) {
                        $query->whereDate('to', '>=', $from_date)->whereDate('to', '<=', $to_date);
                    });
            })->paginate(20);

        $leave_types = LeaveType::all();
        $data = [
            'leaves' => $leaves,
        ];

        foreach ($leave_types as $leave_type) {
            $filteredLeaves = $this->filterLeaves($leaves, $leave_type);
            $totalDaysOff = $this->calculateTotalDaysOff($filteredLeaves, $from_date, $to_date);

            $data[$leave_type->name] = [
                'items' => $filteredLeaves,
                'number_of_days_off' => $totalDaysOff,
            ];
        }

        return $data;
    }

    public function filterLeaves($leaves, $leave_type)
    {
        return $leaves->filter(function ($value, $key) use ($leave_type) {
            return $value['leave_type_id'] == LeaveType::where('name', $leave_type->name)->first()->id;
        });
    }

    public function calculateTotalDaysOff($leaves, $from_date, $to_date)
    {
        $totalDaysOff = 0;

        foreach ($leaves as $leave) {
            $totalDaysOff += $this->findNbofDaysOff($leave, $from_date, $to_date);
        }

        return $totalDaysOff;
    }

    public function fetchRecoveryLeaves(Employee $employee)
    {
        $recovery_leave_type = LeaveType::where('name', 'recovery')->first();
        $leaves = Leave::where('employee_id', $employee->id)->where('leave_type_id', $recovery_leave_type->id)->whereNot('leave_status', self::REJECTED_STATUS)->get();
        return $leaves;
    }

    public function getRecoveryLeaveDays(Employee $employee)
    {
        $leaves = $this->fetchRecoveryLeaves($employee);
        $days = 0;
        foreach ($leaves as $leave) {
            $days += $this->findNbofDaysOff($leave);
        }
        return $days;
    }

    public function getProcessingOfficersForLeaveDestroy(Leave $leave)
    {
        $processing_officers = [];
        if ($leave->employee->hasRole('employee') && $leave->employee->is_supervisor == false) {
            $supervisor = $leave->employee->department->manager;
            $processing_officers[] = $supervisor;
            $hrs = Employee::role('human_resource')->get();
            foreach ($hrs as $hr) {
                $processing_officers[] = $hr;
            }
            $head = Employee::role('head')->get();
            $officers = Employee::role('sg')->get()->concat($head)->all();
            foreach ($officers as $officer) {
                $processing_officers[] = $officer;
            }
        } elseif ($leave->employee->hasRole('employee') && $leave->employee->is_supervisor) {
            $hrs = Employee::role('human_resource')->get();
            foreach ($hrs as $hr) {
                $processing_officers[] = $hr;
            }
            $head = Employee::role('head')->get();
            $officers = Employee::role('sg')->get()->concat($head)->all();
            foreach ($officers as $officer) {
                $processing_officers[] = $officer;
            }
        } else {
            $head = Employee::role('head')->get();
            $officers = Employee::role('sg')->get()->concat($head)->all();
            foreach ($officers as $officer) {
                $processing_officers[] = $officer;
            }
        }
        return $processing_officers;
    }

    public function recoverDays(Leave $leave)
    {
        $employee = $leave->employee;
        $recovery = LeaveType::where('name', 'recovery')->first();
        if ($leave->leave_type_id == $recovery->id) {
            $days = $this->findNbofDaysOff($leave);
            $minutes = $days * self::DAY_TO_MINUTES;
            $employee->overtime_minutes += $minutes;
        }

        if ($leave->use_confessionnels) {
            $employee->confessionnels = $employee->confessionnels - 1;
        }
        $nb_of_days_off = $this->findNbofDaysOff($leave);
        if ($leave->mix_of_leaves) {
            $nb_of_days_off_confessionnels = $this->findNbofDaysOffConfessionnels($leave);
            $employee->confessionnels = $employee->confessionnels + $nb_of_days_off_confessionnels;
        }
        $employee->nb_of_days = $employee->nb_of_days + $nb_of_days_off;

        $expireDate = Carbon::create(null, LeaveConfig::find('expire_month')->value, LeaveConfig::find('expire_day')->value);
        if (now()->isBefore($expireDate)) {
            $employee->prev_leaves = $employee->prev_leaves + $nb_of_days_off;
        }
        $employee->save();
    }

    public function recoverMinutes(Leave $leave)
    {
        $employee = $leave->employee;
        $days = $this->findNbofDaysOff($leave);
        $minutes = $days * self::DAY_TO_MINUTES;
        $employee->overtime_minutes += $minutes;
        $employee->save();
    }

    public function newYearLeaves()
    {
        foreach (Employee::all() as $employee) {
            if ($employee->can_submit_requests) {
                $employee->prev_leaves = $employee->nb_of_days;
                $employee->nb_of_days += 30;
                $employee->save();
            }
        }
    }

    public function expirePrevLeaves()
    {
        foreach (Employee::all() as $employee) {
            if ($employee->can_submit_requests) {
                $employee->nb_of_days -= $employee->prev_leaves;
                $employee->prev_leaves = 0;
                $employee->save();
            }
        }
    }
}
