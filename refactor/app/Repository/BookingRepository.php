<?php

namespace DTApi\Repository;

use DTApi\Events\{SessionEnded, JobWasCreated};
use DTApi\Services\{NotificationService, JobService};
use DTApi\Helpers\{TeHelper, DateTimeHelper};
use DTApi\Models\{Job, User, Distance, UserMeta};
use DTApi\Contracts\{MailerInterface, LoggerInterface};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class BookingRepository extends BaseRepository
{

    private NotificationService $notificationService;
    private JobService $jobService;
    private MailerInterface $mailer;
    private LoggerInterface $logger;


    public function __construct(
        Job $model,
        NotificationService $notificationService,
        JobService $jobService,
        MailerInterface $mailer,
        LoggerInterface $logger
    ) {
        parent::__construct($model);
        $this->notificationService = $notificationService;
        $this->jobService = $jobService;
        $this->mailer = $mailer;
        $this->logger = $logger;
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs(int $userId): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'emergencyJobs' => [],
                'normalJobs' => [],
                'cuser' => null,
                'usertype' => ''
            ];
        }

        $emergencyJobs = [];
        $normalJobs = [];


        if ($user->is('customer')) {
            $jobs = $this->getCustomerJobs($user);
            $userType = 'customer';
        } elseif ($user->is('translator')) {
            $jobs = $this->getTranslatorJobs($user);
            $userType = 'translator';
        } else {
            return [
                'emergencyJobs' => [],
                'normalJobs' => [],
                'cuser' => $user,
                'usertype' => ''
            ];
        }

        foreach ($jobs as $job) {
            if ($job->immediate === 'yes') {
                $emergencyJobs[] = $job;
            } else {
                $normalJobs[] = $job;
            }
        }

        $normalJobs = $this->sortNormalJobs($normalJobs, $userId);

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $user,
            'usertype' => $userType
        ];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory(int $userId, Request $request)
    {
        $page = $request->get('page', 1);

        $user = User::find($userId);
        if (!$user) {
            return $this->getEmptyResponse();
        }

        $emergencyJobs = [];
        $normalJobs = [];
        $userType = '';
        $numpages = 0;

        if ($user->is('customer')) {
            $jobs = $this->getCustomerJobHistory($user, $page);
            $userType = 'customer';
        } elseif ($user->is('translator')) {
            $jobs = $this->getTranslatorJobHistory($user, $page, $numpages);
            $userType = 'translator';
        } else {
            return $this->getEmptyResponse($user);
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'cuser' => $user,
            'usertype' => $userType,
            'numpages' => $numpages,
            'pagenum' => $page
        ];
    }


    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {

        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type != config('customer.customer_role_id')) {
            return $this->errorResponse("Translator cannot create booking");
        }
        $cuser = $user;

        $validationResponse = $this->validateJobFields($data);
        if ($validationResponse['status'] === 'fail') {
            return $validationResponse;
        }

        $due_carbon = $this->processDueDate($data, $immediatetime);

        $data = $this->processJobFor($data);

        $data['job_type'] = $this->determineJobType($consumer_type);

        $data['will_expire_at'] = $this->calculateExpiryTime($data['due'], $data['b_created_at']);

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $cuser->jobs()->create($data);


        return $this->successResponse($job, $data);
    }


    private function validateJobFields($data)
    {
        $requiredFields = [
            'from_language_id' => "Du måste fylla in alla fält",
            'due_date' => "Du måste fylla in alla fält",
            'due_time' => "Du måste fylla in alla fält",
            'customer_phone_type' => "Du måste göra ett val här",
            'duration' => "Du måste fylla in alla fält"
        ];

        foreach ($requiredFields as $field => $message) {
            if (!isset($data[$field]) || $data[$field] == '') {
                return $this->errorResponse($message, $field);
            }
        }

        return ['status' => 'success'];
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail(array $data)
    {
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        $user = $job->user()->first();

        if (isset($data['address'])) {
            $job->address = !empty($data['address']) ? $data['address'] : $user->userMeta->address;
            $job->instructions = !empty($data['instructions']) ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = !empty($data['town']) ? $data['town'] : $user->userMeta->city;
        }

        $job->save();

        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = ['user' => $user, 'job' => $job];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success',
        ];

        $jobData = $this->jobToData($job);
        event(new JobWasCreated($job, $jobData, '*'));
        return $response;
    }


    /**
     * @param $job
     * @return array
     */
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

        $dueDateTime = explode(" ", $job->due);
        $data['due_date'] = $dueDateTime[0] ?? null;
        $data['due_time'] = $dueDateTime[1] ?? null;
        $data['job_for'] = $this->getJobForByGender($job->gender);
        $data['job_for'] = array_merge($data['job_for'], $this->getJobForByCertification($job->certified));
        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completedDate = now();
        $jobId = $post_data['job_id'];
        $jobDetail = Job::with('translatorJobRel')->findOrFail($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $sessionTime = $diff->h . ':' . $diff->i . ':' . $diff->s;

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $sessionTime;
        $jobDetail->save();

        $user = $jobDetail->user;
        $email = $jobDetail->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;

        $sessionExplode = explode(':', $sessionTime);
        $formattedSessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $jobDetail,
            'session_time' => $formattedSessionTime,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translator = $jobDetail->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
        if ($translator) {
            $user = $translator->user;
            $email = $user->email;
            $name = $user->name;
            $data['for_text'] = 'lön';
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

            $translator->completed_at = $completedDate;
            $translator->completed_by = $post_data['userid'];
            $translator->save();
        }


        Event::fire(new SessionEnded($jobDetail, ($post_data['userid'] == $jobDetail->user_id) ? $translator->user_id : $jobDetail->user_id));
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {

        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;

        switch ($translator_type) {
            case 'professional':
                $job_type = 'paid';
                break;
            case 'rwstranslator':
                $job_type = 'rws';
                break;
            case 'volunteer':
                $job_type = 'unpaid';
                break;
            default:
                $job_type = 'unpaid';
                break;
        }

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        $filtered_job_ids = $job_ids->filter(function ($job) use ($user_id) {

            $job_detail = Job::find($job->id);
            $check_town = Job::checkTowns($job_detail->user_id, $user_id);

            return !(
                ($job_detail->customer_phone_type === 'no' || $job_detail->customer_phone_type === '') &&
                $job_detail->customer_physical_type === 'yes' &&
                !$check_town
            );
        });

        return TeHelper::convertJobIdsInObjs($filtered_job_ids);
    }


    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();
        $delpay_translator_array = array();

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) {
                if (!$this->isNeedToSendPush($oneUser->id)) continue;


                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;

                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) {
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if ($job_checker != 'userCanNotAcceptJob') {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';

        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }

        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true);
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {

            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {

            $message = $phoneJobMessageTemplate;
        } else {
            $message = '';
        }
        Log::info($message);

        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $notificationPreference = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $notificationPreference !== 'yes';
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());


        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

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

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);

        curl_close($ch);
    }


    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $translator_type = match ($job->job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
            default => null
        };

        $translator_level = $this->getTranslatorLevels($job->certified);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();

        return User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $blacklist);
    }

    private function getTranslatorLevels($certified)
    {
        $levels = [];

        if (empty($certified)) {
            return ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
        }

        switch ($certified) {
            case 'yes':
            case 'both':
                $levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
                break;
            case 'law':
            case 'n_law':
                $levels = ['Certified with specialisation in law'];
                break;
            case 'health':
            case 'n_health':
                $levels = ['Certified with specialisation in health care'];
                break;
            case 'normal':
                $levels = ['Layman', 'Read Translation courses'];
                break;
        }

        return $levels;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];
        $langChanged = false;
        $old_time = null;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);
        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        }

        if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
        if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
        if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
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
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];


        if ($data['status'] == 'pending') {
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();

            $job_data = $this->jobToData($job);
            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;

            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');

            return true;
        }

        if ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {

        $job->status = $data['status'];

        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
            return false;
        }

        if ($data['status'] == 'timedout') {
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if (empty($data['admin_comments'])) {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            if (empty($data['sesion_time'])) {
                return false;
            }

            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = now();
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $user = $job->user()->first();
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura'
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
            if ($translator) {
                $email = $translator->user->email;
                $name = $translator->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $dataEmail = [
                    'user' => $translator->user,
                    'job' => $job,
                    'session_time' => $session_time,
                    'for_text' => 'lön'
                ];
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            }
        }

        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        //        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
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


        //        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {

        if ($data['status'] == 'timedout') {
            if (empty($data['admin_comments'])) {
                return false;
            }

            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];

            $job->save();
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id;
        $data = ['user' => $user, 'job' => $job];

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

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];

        $this->mailer->send(
            !empty($job->user_email) ? $job->user_email : $user->email,
            $user->name,
            $subject,
            'emails.job-changed-date',
            $data
        );

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send(
            $translator->email,
            $translator->name,
            $subject,
            'emails.job-changed-date',
            $data
        );
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        $this->mailer->send(
            !empty($job->user_email) ? $job->user_email : $user->email,
            $user->name,
            $subject,
            'emails.job-changed-lang',
            $data
        );

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send(
            $translator->email,
            $translator->name,
            $subject,
            'emails.job-changed-lang',
            $data
        );
    }


    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );


        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [
            'job_id'               => $job->id,
            'from_language_id'     => $job->from_language_id,
            'immediate'            => $job->immediate,
            'duration'             => $job->duration,
            'status'               => $job->status,
            'gender'               => $job->gender,
            'certified'            => $job->certified,
            'due'                  => $job->due,
            'job_type'             => $job->job_type,
            'customer_phone_type'  => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town'        => $user_meta->city,
            'customer_type'        => $user_meta->customer_type,
            'due_date'             => explode(" ", $job->due)[0],
            'due_time'             => explode(" ", $job->due)[1],
            'job_for'              => []
        ];


        if ($job->gender) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }


        if ($job->certified) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = ($job->certified == 'yes') ? 'certified' : $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }


    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
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

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $cuser = $user;

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }


        if ($job->status === 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $data = [
                'user' => $user,
                'job'  => $job
            ];

            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            $jobs = $this->getPotentialJobs($cuser);

            return [
                'status' => 'success',
                'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true)
            ];
        }

        return [
            'status'  => 'fail',
            'message' => 'Jobbstatus ogiltig, vänligen kontrollera.'
        ];
    }


    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = array();
    
        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
            return $response;
        }

        if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            
            $job->status = 'assigned';
            $job->save();
    
       
            $user = $job->user()->first();
            $mailer = new AppMailer();
    
      
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $data = [
                'user' => $user,
                'job'  => $job
            ];
    
      
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    
          
            $data = [
                'notification_type' => 'job_accepted',
            ];
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $msg_text = [
                "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
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
    
        return $response;
    }
    
    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
         
            $job->withdraw_at = Carbon::now();
            
        
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            
            // Save job status and fire cancellation event
            $job->save();
            Event::fire(new JobWasCanceled($job));
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id)); // send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                //                Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                    unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        //        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
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
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
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
    
  
        if ($this->isSuperAdmin($cuser)) {
            $this->applySuperAdminFilters($allJobs, $requestdata);
        } else {
            $this->applyConsumerFilters($allJobs, $requestdata, $consumer_type);
        }
    
        $this->applyCommonFilters($allJobs, $requestdata);
    
        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }
    
        return $allJobs;
    }
    
 
    private function isSuperAdmin($cuser)
    {
        return $cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID');
    }
    

    private function applySuperAdminFilters($allJobs, $requestdata)
    {
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                return ['count' => $allJobs->count()];
            }
        }
    
        $this->applySuperAdminJobFilters($allJobs, $requestdata);
    }
    

    private function applySuperAdminJobFilters($allJobs, $requestdata)
    {
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            is_array($requestdata['id'])
                ? $allJobs->whereIn('id', $requestdata['id'])
                : $allJobs->where('id', $requestdata['id']);
        }
    
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
    
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('status', $requestdata['status']);
        }
    
        $this->applyDateFilters($allJobs, $requestdata);
    }
    
 
    private function applyConsumerFilters($allJobs, $requestdata, $consumer_type)
    {
        if ($consumer_type == 'RWS') {
            $allJobs->where('job_type', '=', 'rws');
        } else {
            $allJobs->where('job_type', '=', 'unpaid');
        }
    
        $this->applyConsumerJobFilters($allJobs, $requestdata);
    }
    
  
    private function applyConsumerJobFilters($allJobs, $requestdata)
    {
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $allJobs->where('id', $requestdata['id']);
        }
    
        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $allJobs->where('user_id', '=', $user->id);
            }
        }
    
        $this->applyDateFilters($allJobs, $requestdata);
    }
    
    private function applyCommonFilters($allJobs, $requestdata)
    {
        
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
        }
    
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
        }
    
        if (isset($requestdata['physical'])) {
            $allJobs->where('customer_physical_type', $requestdata['physical']);
            $allJobs->where('ignore_physical', 0);
        }
    
        if (isset($requestdata['phone'])) {
            $allJobs->where('customer_phone_type', $requestdata['phone']);
            if (isset($requestdata['physical']))
                $allJobs->where('ignore_physical_phone', 0);
        }
    
        if (isset($requestdata['flagged'])) {
            $allJobs->where('flagged', $requestdata['flagged']);
            $allJobs->where('ignore_flagged', 0);
        }
    
        if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
            $allJobs->whereDoesntHave('distance');
        }
    
        if (isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }
    
        if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }
    
        if (isset($requestdata['booking_type'])) {
            if ($requestdata['booking_type'] == 'physical') {
                $allJobs->where('customer_physical_type', 'yes');
            }
            if ($requestdata['booking_type'] == 'phone') {
                $allJobs->where('customer_phone_type', 'yes');
            }
        }
    
        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    }
    
 
    private function applyDateFilters($allJobs, $requestdata)
    {
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }
    }
    

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);
        //$datareopen['updated_at'] = date('Y-m-d H:i:s');

        //        $this->logger->addInfo('USER #' . Auth::user()->id . ' reopen booking #: ' . $jobid);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    private function getCustomerJobs(User $user)
    {
        return $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }

    private function getTranslatorJobs(User $user)
    {
        $jobs = Job::getTranslatorJobs($user->id, 'new');
        return $jobs->pluck('jobs')->all();
    }

    private function sortNormalJobs(array $jobs, int $userId): array
    {
        return collect($jobs)->each(function ($job) use ($userId) {
            $job['usercheck'] = Job::checkParticularJob($userId, $job);
        })->sortBy('due')->all();
    }

    private function getEmptyResponse(User $user = null): array
    {
        return [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'jobs' => [],
            'cuser' => $user,
            'usertype' => '',
            'numpages' => 0,
            'pagenum' => 0
        ];
    }

    private function getCustomerJobHistory(User $user, int $page)
    {
        return $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15, ['*'], 'page', $page);
    }

    private function getTranslatorJobHistory(User $user, int $page, &$numpages)
    {
        $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic', $page);
        $totalJobs = $jobs->total();
        $numpages = ceil($totalJobs / 15);

        return $jobs;
    }



    private function processDueDate($data, $immediatetime)
    {
        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinute($immediatetime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            return $due_carbon;
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');

            if ($due_carbon->isPast()) {
                return $this->errorResponse("Can't create booking in past");
            }

            return $due_carbon;
        }
    }

    private function processJobFor($data)
    {
        $data['gender'] = $this->determineGender($data['job_for']);
        $data['certified'] = $this->determineCertification($data['job_for']);

        return $data;
    }

    private function determineGender($jobFor)
    {
        if (in_array('male', $jobFor)) {
            return 'male';
        } elseif (in_array('female', $jobFor)) {
            return 'female';
        }

        return null;
    }

    private function determineCertification($jobFor)
    {
        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) {
            return 'both';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) {
            return 'n_law';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_health', $jobFor)) {
            return 'n_health';
        } elseif (in_array('certified', $jobFor)) {
            return 'yes';
        } elseif (in_array('certified_in_law', $jobFor)) {
            return 'law';
        } elseif (in_array('certified_in_health', $jobFor)) {
            return 'health';
        }

        return 'normal';
    }


    private function determineJobType($consumer_type)
    {
        if ($consumer_type == 'rwsconsumer') {
            return 'rws';
        } elseif ($consumer_type == 'ngo') {
            return 'unpaid';
        }

        return 'paid';
    }


    private function calculateExpiryTime($due, $created_at)
    {
        return TeHelper::willExpireAt($due, $created_at);
    }


    private function successResponse($job, $data)
    {
        $response = [
            'status' => 'success',
            'id' => $job->id,
            'job_for' => $this->getJobForText($data),
            'customer_town' => $job->userMeta->city,
            'customer_type' => $job->userMeta->customer_type
        ];

        // Event::fire(new JobWasCreated($job, $data, '*')); // Uncomment if needed
        // $this->sendNotificationToSuitableTranslators($job->id, $data, '*'); // Uncomment for push notification

        return $response;
    }

    private function getJobForText($data)
    {
        $jobForText = [];

        if ($data['gender']) {
            $jobForText[] = $data['gender'] == 'male' ? 'Man' : 'Kvinna';
        }

        if ($data['certified']) {
            if ($data['certified'] == 'both') {
                $jobForText[] = 'normal';
                $jobForText[] = 'certified';
            } else {
                $jobForText[] = $data['certified'];
            }
        }

        return $jobForText;
    }


    private function errorResponse($message, $field = null)
    {
        return [
            'status' => 'fail',
            'message' => $message,
            'field_name' => $field
        ];
    }

    private function getJobForByGender($gender)
    {
        $genderMap = [
            'male' => 'Man',
            'female' => 'Kvinna',
        ];

        return isset($genderMap[$gender]) ? [$genderMap[$gender]] : [];
    }

    private function getJobForByCertification($certified)
    {
        $certificationMap = [
            'both' => ['Godkänd tolk', 'Auktoriserad'],
            'yes' => ['Auktoriserad'],
            'n_health' => ['Sjukvårdstolk'],
            'law' => ['Rätttstolk'],
            'n_law' => ['Rätttstolk'],
        ];

        return isset($certificationMap[$certified]) ? $certificationMap[$certified] : [$certified];
    }
}
