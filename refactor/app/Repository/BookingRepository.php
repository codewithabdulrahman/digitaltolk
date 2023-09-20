<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    public function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->initializeLogger();
    }

    protected function initializeLogger()
    {
        $logFileName = 'laravel-' . date('Y-m-d') . '.log';
        $logPath = storage_path('logs/admin/' . $logFileName);

        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobs($cuser);
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = $this->getTranslatorJobs($cuser);
                $usertype = 'translator';
            }

            if ($jobs) {
                [$emergencyJobs, $normalJobs] = $this->splitJobsByType($jobs);
                $normalJobs = $this->addUserCheckInfo($normalJobs, $user_id);
            }
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1);
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobsHistory($cuser, $page);
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                [$jobs, $totalJobs, $numPages] = $this->getTranslatorJobsHistory($cuser, $page);
                $usertype = 'translator';
            }

            if ($usertype === 'customer') {
                return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
            } elseif ($usertype === 'translator') {
                return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numPages, 'pagenum' => $page];
            }
        }
    }

    protected function getCustomerJobs($cuser)
    {
        return $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
    }

    protected function getTranslatorJobs($cuser)
    {
        $jobs = Job::getTranslatorJobs($cuser->id, 'new');
        return $jobs->pluck('jobs')->all();
    }

    protected function getCustomerJobsHistory($cuser, $page)
    {
        return $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15, ['*'], 'page', $page);
    }

    protected function getTranslatorJobsHistory($cuser, $page)
    {
        $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
        $totaljobs = $jobs_ids->total();
        $numPages = ceil($totaljobs / 15);

        return [$jobs_ids, $totaljobs, $numPages];
    }

    protected function splitJobsByType($jobs)
    {
        $emergencyJobs = [];
        $normalJobs = [];

        foreach ($jobs as $jobitem) {
            if ($jobitem->immediate == 'yes') {
                $emergencyJobs[] = $jobitem;
            } else {
                $normalJobs[] = $jobitem;
            }
        }

        return [$emergencyJobs, $normalJobs];
    }

    protected function addUserCheckInfo($jobs, $user_id)
    {
        return collect($jobs)->each(function ($item, $key) use ($user_id) {
            $item['usercheck'] = Job::checkParticularJob($user_id, $item);
        })->sortBy('due')->all();
    }

    public function store($user, $data)
    {
        $response = [];

        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
            return $response;
        }

        if (!isset($data['from_language_id'])) {
            return $this->validationErrorResponse("from_language_id", "Du måste fylla in alla fält");
        }

        if ($data['immediate'] == 'no') {
            if (empty($data['due_date']) || empty($data['due_time'])) {
                return $this->validationErrorResponse("due_date", "Du måste fylla in alla fält");
            }

            if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                return $this->validationErrorResponse("customer_phone_type", "Du måste göra ett val här");
            }

            if (empty($data['duration'])) {
                return $this->validationErrorResponse("duration", "Du måste fylla in alla fält");
            }
        } elseif (empty($data['duration'])) {
            return $this->validationErrorResponse("duration", "Du måste fylla in alla fält");
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinute($immediatetime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');

            if ($due_carbon->isPast()) {
                return $this->validationErrorResponse("due_date", "Can't create booking in the past");
            }
        }

        // Handle job_for, gender, and certified fields
        $data = $this->handleJobForFields($data);

        if ($consumer_type == 'rwsconsumer') {
            $data['job_type'] = 'rws';
        } elseif ($consumer_type == 'ngo') {
            $data['job_type'] = 'unpaid';
        } elseif ($consumer_type == 'paid') {
            $data['job_type'] = 'paid';
        }

        $data['b_created_at'] = now();

        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $user->jobs()->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;

        // Set job_for, customer_town, and customer_type in response
        $response = $this->setResponseFields($job, $response, $user);

        return $response;
    }

    private function validationErrorResponse($fieldName, $message)
    {
        return ['status' => 'fail', 'message' => $message, 'field_name' => $fieldName];
    }

    private function handleJobForFields($data)
    {
        $jobFor = $data['job_for'] ?? [];

        if (in_array('male', $jobFor)) {
            $data['gender'] = 'male';
        } elseif (in_array('female', $jobFor)) {
            $data['gender'] = 'female';
        }

        if (in_array('normal', $jobFor)) {
            $data['certified'] = 'normal';
        } elseif (in_array('certified', $jobFor)) {
            $data['certified'] = 'yes';
        } elseif (in_array('certified_in_law', $jobFor)) {
            $data['certified'] = 'law';
        } elseif (in_array('certified_in_health', $jobFor)) {
            $data['certified'] = 'health';
        }

        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) {
            $data['certified'] = 'both';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) {
            $data['certified'] = 'n_law';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_health', $jobFor)) {
            $data['certified'] = 'n_health';
        }

        return $data;
    }

    private function setResponseFields($job, $response, $user)
    {
        $response['job_for'] = [];
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $response['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $response['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $response['job_for'][] = 'normal';
                $response['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $response['job_for'][] = 'certified';
            } else {
                $response['job_for'][] = $job->certified;
            }
        }

        $response['customer_town'] = $user->userMeta->city;
        $response['customer_type'] = $user->userMeta->customer_type;

        return $response;
    }

    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::find($data['user_email_job_id']);

        if (!$job) {
            return $this->responseError("Job not found.");
        }

        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $job->address = $data['address'] ?? $job->user->userMeta->address;
            $job->instructions = $data['instructions'] ?? $job->user->userMeta->instructions;
            $job->town = $data['town'] ?? $job->user->userMeta->city;
        }

        $job->save();

        $email = $job->user_email ?? $job->user->email;
        $name = $job->user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = ['user' => $job->user, 'job' => $job];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response = [
            'type' => $user_type,
            'job' => $job,
            'status' => 'success',
        ];

        $jobData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $jobData, '*'));

        return $response;
    }

    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        [$dueDate, $dueTime] = explode(" ", $job->due);
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;

        $data['job_for'] = [];

        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } elseif ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rättstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    private function responseError($message)
    {
        return ['status' => 'fail', 'message' => $message];
    }

    public function jobEnd($post_data = [])
    {
        $jobId = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobId);

        if (!$job) {
            return $this->responseError("Job not found.");
        }

        $completedDate = now();
        $dueDate = $job->due;
        $interval = $completedDate->diffAsCarbonInterval($dueDate);
        $sessionTime = $interval->format('%h tim %i min');

        $job->update([
            'end_at' => $completedDate,
            'status' => 'completed',
            'session_time' => $sessionTime,
        ]);

        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'faktura',
        ];

        $this->sendEmail($email, $name, $subject, 'emails.session-ended', $data);

        $translatorRel = $job->translatorJobRel->first();

        if ($post_data['userid'] == $job->user_id) {
            $recipientUserId = $translatorRel->user_id;
        } else {
            $recipientUserId = $job->user_id;
        }

        Event::fire(new SessionEnded($job, $recipientUserId));

        $translator = $translatorRel->user;
        $email = $translator->email;
        $name = $translator->name;

        $data['for_text'] = 'lön';

        $this->sendEmail($email, $name, $subject, 'emails.session-ended', $data);

        $translatorRel->update([
            'completed_at' => $completedDate,
            'completed_by' => $post_data['userid'],
        ]);
    }

    public function getPotentialJobIdsWithUserId($user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;
        $jobType = 'unpaid';

        if ($translatorType == 'professional') {
            $jobType = 'paid';
        } elseif ($translatorType == 'rwstranslator') {
            $jobType = 'rws';
        } elseif ($translatorType == 'volunteer') {
            $jobType = 'unpaid';
        }

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $languages, $gender, $translatorLevel);

        $jobIds = array_filter($jobIds, function ($jobId) use ($user_id) {
            $job = Job::find($jobId);
            $jobUserId = $job->user_id;
            $checkTown = Job::checkTowns($jobUserId, $user_id);
            return !(($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checkTown);
        });

        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    private function sendEmail($email, $name, $subject, $view, $data)
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, $view, $data);
    }

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $suitableTranslators = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $exclude_user_id)
            ->whereDoesntHave('userMeta', function ($query) {
                $query->where('not_get_emergency', 'yes');
            })
            ->whereHas('jobs', function ($query) use ($job) {
                $query->where('id', $job->id)
                    ->where('status', 'pending')
                    ->where(function ($query) {
                        $query->where('customer_phone_type', 'yes')
                            ->orWhere('customer_physical_type', 'yes');
                    });
            })
            ->get();

        $translatorArray = [];
        $delayPushTranslatorArray = [];

        foreach ($suitableTranslators as $oneUser) {
            if ($this->isNeedToDelayPush($oneUser->id)) {
                $delayPushTranslatorArray[] = $oneUser;
            } else {
                $translatorArray[] = $oneUser;
            }
        }

        $language = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $notificationType = 'suitable_job';

        $msgContents = $data['immediate'] == 'no'
            ? "Ny bokning för $language tolk {$data['duration']}min {$data['due']}"
            : "Ny akutbokning för $language tolk {$data['duration']}min";

        $msgText = ["en" => $msgContents];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayPushTranslatorArray, $msgText, $data]);

        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($delayPushTranslatorArray, $job->id, $data, $msgText, true);
    }

    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));

        $message = $job->customer_physical_type == 'yes' && $job->customer_phone_type != 'yes'
            ? $physicalJobMessageTemplate
            : $phoneJobMessageTemplate;

        Log::info($message);

        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info("Send SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true));
        }

        return count($translators);
    }

    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');

        return $not_get_nighttime === 'yes';
    }

    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');

        return $not_get_notification !== 'yes';
    }

    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod'
            ? config('app.prodOnesignalAppID')
            : config('app.devOnesignalAppID');

        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod'
            ? config('app.prodOnesignalApiKey')
            : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound,
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    public function getPotentialTranslators(Job $job)
    {
        $translator_type = match ($job->job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
        };

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            if (in_array($job->certified, ['yes', 'both'])) {
                $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
            } elseif (in_array($job->certified, ['law', 'n_law'])) {
                $translator_level = ['Certified with specialisation in law'];
            } elseif (in_array($job->certified, ['health', 'n_health'])) {
                $translator_level = ['Certified with specialisation in health care'];
            } elseif ($job->certified == 'normal' || $job->certified == null) {
                $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

        return $users;
    }

    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', null)->firstOr(function () {
            return $job->translatorJobRel->where('completed_at', '!=', null)->first();
        });

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);

        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);

        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);

        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;

        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status'],
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job,
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all suitable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout' && $data['admin_comments'] == '') {
            return false;
        }

        if ($data['status'] == 'timedout') {
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }

    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '') {
            return false;
        }

        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura',
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön',
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }

        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        }

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job,
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
    }

    /**
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $due_explode = explode(' ', $due);
        $msgTextKey = ($job->customer_physical_type == 'yes') ? 'plats' : 'telefon';

        $msg_text = [
            'en' => "Detta är en påminnelse om att du har en {$language} tolkning (på {$msgTextKey} i {$job->town}) kl {$due_explode[1]} på {$due_explode[0]} som vara i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!"
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        if ($data['status'] === 'timedout') {
            $job->status = $data['status'];
            if ($data['admin_comments'] === '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job,
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job,
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email,
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email,
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due,
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job,
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time,
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time,
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang,
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendExpiredNotification($job, $user)
    {
        $data = [
            'notification_type' => 'job_expired',
        ];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        $msg_text = [
            'en' => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.',
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [
            'job_id'                 => $job->id,
            'from_language_id'       => $job->from_language_id,
            'immediate'              => $job->immediate,
            'duration'               => $job->duration,
            'status'                 => $job->status,
            'gender'                 => $job->gender,
            'certified'              => $job->certified,
            'due'                    => $job->due,
            'job_type'               => $job->job_type,
            'customer_phone_type'    => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town'          => $user_meta->city,
            'customer_type'          => $user_meta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = [];

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $msg_text = [];

        if ($job->customer_physical_type == 'yes') {
            $msg_text['en'] = "Du har nu fått platstolkningen för {$language} kl {$duration} den {$due}. Vänligen säkerställ att du är förberedd för den tiden. Tack!";
        } else {
            $msg_text['en'] = "Du har nu fått telefontolkningen för {$language} kl {$duration} den {$due}. Vänligen säkerställ att du är förberedd för den tiden. Tack!";
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    private function getUserTagsStringFromArray($users)
    {
        $user_tags = '[';
        $first = true;

        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }

        $user_tags .= ']';

        return $user_tags;
    }

    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }

                $data = [
                    'user' => $user,
                    'job'  => $job,
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }

    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job,
                ];

                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = [
                    'notification_type' => 'job_accepted',
                ];

                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

                $msg_text = [
                    'en' => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.',
                ];

                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }

                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
            } else {
                $job->status = 'withdrawafter24';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $this->sendCancellationNotificationToTranslator($translator, $job);
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $this->sendCancellationNotificationToCustomer($customer, $job);
                }
                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id);
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
            }
        }

        return $response;
    }

    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';

        if ($cuser_meta->translator_type == 'professional') {
            $job_type = 'paid';
        } elseif ($cuser_meta->translator_type == 'rwstranslator') {
            $job_type = 'rws';
        }

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = $languages->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                unset($job_ids[$k]);
            }

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !Job::checkTowns($job->user_id, $cuser->id)) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');
        $job = $job_detail;
        $job->end_at = now();
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura',
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön',
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();

        $response['status'] = 'success';
        return $response;
    }

    public function customerNotCall($post_data)
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');
        $job = $job_detail;
        $job->end_at = $completeddate;
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;

        $job->save();
        $tr->save();

        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
            $this->applyFilters($allJobs, $requestdata);

            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        } else {
            $allJobs->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
            $allJobs->where('job_type', '=', $consumer_type == 'RWS' ? 'rws' : 'unpaid');
            $this->applyFilters($allJobs, $requestdata);

            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }

        return $allJobs;
    }

    private function applyFilters($query, $filters)
    {
        if (isset($filters['feedback']) && $filters['feedback'] != 'false') {
            $query->where('ignore_feedback', '0');
            $query->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });

            if (isset($filters['count']) && $filters['count'] != 'false') {
                return ['count' => $query->count()];
            }
        }

        if (isset($filters['id']) && $filters['id'] != '') {
            if (is_array($filters['id'])) {
                $query->whereIn('id', $filters['id']);
            } else {
                $query->where('id', $filters['id']);
            }
        }

        if (isset($filters['lang']) && $filters['lang'] != '') {
            $query->whereIn('from_language_id', $filters['lang']);
        }

        if (isset($filters['status']) && $filters['status'] != '') {
            $query->whereIn('status', $filters['status']);
        }

        if (isset($filters['expired_at']) && $filters['expired_at'] != '') {
            $query->where('expired_at', '>=', $filters['expired_at']);
        }

        if (isset($filters['will_expire_at']) && $filters['will_expire_at'] != '') {
            $query->where('will_expire_at', '>=', $filters['will_expire_at']);
        }

        if (isset($filters['customer_email']) && count($filters['customer_email']) && $filters['customer_email'] != '') {
            $users = DB::table('users')->whereIn('email', $filters['customer_email'])->get();
            if ($users) {
                $query->whereIn('user_id', collect($users)->pluck('id')->all());
            }
        }

        if (isset($filters['translator_email']) && count($filters['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $filters['translator_email'])->get();
            if ($users) {
                $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                $query->whereIn('id', $allJobIDs);
            }
        }

        if (isset($filters['filter_timetype']) && $filters['filter_timetype'] == "created") {
            if (isset($filters['from']) && $filters['from'] != "") {
                $query->where('created_at', '>=', $filters["from"]);
            }
            if (isset($filters['to']) && $filters['to'] != "") {
                $to = $filters["to"] . " 23:59:00";
                $query->where('created_at', '<=', $to);
            }
            $query->orderBy('created_at', 'desc');
        }

        if (isset($filters['filter_timetype']) && $filters['filter_timetype'] == "due") {
            if (isset($filters['from']) && $filters['from'] != "") {
                $query->where('due', '>=', $filters["from"]);
            }
            if (isset($filters['to']) && $filters['to'] != "") {
                $to = $filters["to"] . " 23:59:00";
                $query->where('due', '<=', $to);
            }
            $query->orderBy('due', 'desc');
        }

        if (isset($filters['job_type']) && $filters['job_type'] != '') {
            $query->whereIn('job_type', $filters['job_type']);
        }

        if (isset($filters['physical'])) {
            $query->where('customer_physical_type', $filters['physical']);
            $query->where('ignore_physical', 0);
        }

        if (isset($filters['phone'])) {
            $query->where('customer_phone_type', $filters['phone']);
            if (isset($filters['physical']))
                $query->where('ignore_physical_phone', 0);
        }

        if (isset($filters['flagged'])) {
            $query->where('flagged', $filters['flagged']);
            $query->where('ignore_flagged', 0);
        }

        if (isset($filters['distance']) && $filters['distance'] == 'empty') {
            $query->whereDoesntHave('distance');
        }

        if (isset($filters['salary']) &&  $filters['salary'] == 'yes') {
            $query->whereDoesntHave('user.salaries');
        }

        if (isset($filters['consumer_type']) && $filters['consumer_type'] != '') {
            $query->whereHas('user.userMeta', function ($q) use ($filters) {
                $q->where('consumer_type', $filters['consumer_type']);
            });
        }

        if (isset($filters['booking_type'])) {
            if ($filters['booking_type'] == 'physical') {
                $query->where('customer_physical_type', 'yes');
            }
            if ($filters['booking_type'] == 'phone') {
                $query->where('customer_phone_type', 'yes');
            }
        }
    }

    public function alerts()
    {
        // Retrieve jobs
        $jobs = Job::all();

        // Filter jobs based on session duration
        $filteredJobs = $this->filterJobsByDuration($jobs);

        // Get job IDs from filtered jobs
        $jobIds = $this->getJobIdsFromJobs($filteredJobs);

        // Retrieve additional data
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->toArray();
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->toArray();

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        // Apply filters to the jobs
        $filteredJobs = $this->applyFiltersToJobs($jobIds, $requestdata, $cuser);

        return [
            'allJobs' => $filteredJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata,
        ];
    }

    private function filterJobsByDuration($jobs)
    {
        $sesJobs = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff >= $job->duration && $diff >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }

        return $sesJobs;
    }

    private function getJobIdsFromJobs($jobs)
    {
        return collect($jobs)->pluck('id')->toArray();
    }

    private function applyFiltersToJobs($jobIds, $filters, $cuser)
    {
        $query = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->whereIn('jobs.id', $jobIds)
            ->where('jobs.ignore', 0);

        $query->orderBy('jobs.created_at', 'desc');

        return $query->paginate(15);
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted(Request $request)
    {
        $filters = $request->all();
        $user = Auth::user();

        // Initialize the base query
        $query = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0);

        // Apply filters
        if ($user && ($user->is('superadmin') || $user->is('admin'))) {
            if (isset($filters['lang']) && !empty($filters['lang'])) {
                $query->whereIn('jobs.from_language_id', $filters['lang']);
            }
            if (isset($filters['status']) && !empty($filters['status'])) {
                $query->whereIn('jobs.status', $filters['status']);
            }
            if (isset($filters['customer_email']) && !empty($filters['customer_email'])) {
                $user = DB::table('users')->where('email', $filters['customer_email'])->first();
                if ($user) {
                    $query->where('jobs.user_id', $user->id);
                }
            }
            if (isset($filters['translator_email']) && !empty($filters['translator_email'])) {
                $user = DB::table('users')->where('email', $filters['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $query->whereIn('jobs.id', $allJobIDs);
                }
            }
            if (isset($filters['filter_timetype']) && $filters['filter_timetype'] == "created") {
                if (isset($filters['from']) && !empty($filters['from'])) {
                    $query->where('jobs.created_at', '>=', $filters["from"]);
                }
                if (isset($filters['to']) && !empty($filters['to'])) {
                    $to = $filters["to"] . " 23:59:59";
                    $query->where('jobs.created_at', '<=', $to);
                }
            }
            if (isset($filters['filter_timetype']) && $filters['filter_timetype'] == "due") {
                if (isset($filters['from']) && !empty($filters['from'])) {
                    $query->where('jobs.due', '>=', $filters["from"]);
                }
                if (isset($filters['to']) && !empty($filters['to'])) {
                    $to = $filters["to"] . " 23:59:59";
                    $query->where('jobs.due', '<=', $to);
                }
            }
            if (isset($filters['job_type']) && !empty($filters['job_type'])) {
                $query->whereIn('jobs.job_type', $filters['job_type']);
            }

            // Additional filters can be added here

            // Perform the final query and paginate the results
            $query->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now())
                ->orderBy('jobs.created_at', 'desc');

            $allJobs = $query->paginate(15);
        } else {
            // Handle unauthorized user here, or return an empty result
            $allJobs = [];
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->all();
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->all();

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $filters,
        ];
    }

    public function ignoreExpiring($id)
    {
        $this->ignoreJob($id, 'ignore', 1);
    }

    public function ignoreExpired($id)
    {
        $this->ignoreJob($id, 'ignore_expired', 1);
    }

    public function ignoreJob($id, $field, $value)
    {
        $job = Job::find($id);
        if ($job) {
            $job->$field = $value;
            $job->save();
            return ['success', 'Changes saved'];
        }
        return ['error', 'Job not found'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);

        if (!$job) {
            return ['error', 'Job not found'];
        }

        $jobData = $job->toArray();

        $now = now();
        $willExpireAt = TeHelper::willExpireAt($jobData['due'], $now);

        $data = [
            'created_at' => $now,
            'will_expire_at' => $willExpireAt,
            'updated_at' => $now,
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => $now,
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => $now,
            'will_expire_at' => $willExpireAt,
        ];

        if ($jobData['status'] != 'timedout') {
            Job::where('id', $jobid)->update($datareopen);
            $newJobId = $jobid;
        } else {
            $jobData['status'] = 'pending';
            $jobData['created_at'] = $now;
            $jobData['updated_at'] = $now;
            $jobData['will_expire_at'] = $willExpireAt;
            $jobData['cust_16_hour_email'] = 0;
            $jobData['cust_48_hour_email'] = 0;
            $jobData['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;

            $newJob = Job::create($jobData);
            $newJobId = $newJob->id;
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        $translatorData = [
            'created_at' => $now,
            'will_expire_at' => $willExpireAt,
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => $now,
        ];

        Translator::create($translatorData);

        $this->sendNotificationByAdminCancelJob($newJobId);

        return ['success', 'Job reopened successfully'];
    }


    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        $hours = floor($time / 60);
        $minutes = $time % 60;

        if ($hours > 0 && $minutes > 0) {
            return sprintf($format, $hours, $minutes);
        } elseif ($hours > 0) {
            return sprintf('%02dh', $hours);
        } elseif ($minutes > 0) {
            return sprintf('%02dmin', $minutes);
        }

        return '0min'; // Return '0min' if $time is 0 or negative
    }
}
