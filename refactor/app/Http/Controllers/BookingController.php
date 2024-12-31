<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Models\Distance;
use DTApi\Http\Requests\{
    BookingRequest,
    EmailRequest,
    JobHistoryRequest
};
use DTApi\Repository\BookingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(BookingRequest $request): JsonResponse
    {
        try {
            $user = $request->__authenticatedUser;

            if ($userId = $request->get('user_id')) {
                $response = $this->repository->getUsersJobs($userId);
            } elseif (in_array($user->user_type, config('roles.admin_roles'))) {
                $response = $this->repository->getAll($request);
            } else {
                return $this->sendUnauthorized('You do not have permission to view user data.');
            }

            return $this->successResponse($response);
        } catch (\Exception $e) {
            return $this->errorResponse('Error fetching bookings', $e);
        }
    }


    /**
     * @param $id
     * @return mixed
     */
    public function show(int $id): JsonResponse
    {
        try {
            $job = $this->repository->with('translatorJobRel.user')->find($id);

            if (!$job) {
                return $this->notFoundResponse('Booking not found');
            }

            return $this->successResponse($job);
        } catch (\Exception $e) {
            return $this->errorResponse('Error fetching booking details', $e);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->store(
                $request->user(),
                $request->validated()
            );

            return $this->successResponse(
                $response,
                'Booking created successfully',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->errorResponse('Error creating booking', $e);
        }
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update(int $id, BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->updateJob(
                $id,
                $request->except(['_token', 'submit']),
                $request->user()
            );

            return $this->successResponse($response, 'Booking updated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error updating booking', $e);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(EmailRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->storeJobEmail($request->validated());
            return $this->successResponse($response, 'Job email sent successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error sending job email', $e);
        }
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(JobHistoryRequest $request): JsonResponse
    {
        try {
            $userId = $request->get('user_id');

            if (!$userId) {
                return $this->errorResponse('User ID is required', null, Response::HTTP_BAD_REQUEST);
            }

            $response = $this->repository->getUsersJobsHistory($userId, $request);
            return $this->successResponse($response);
        } catch (\Exception $e) {
            return $this->errorResponse('Error fetching job history', $e);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->acceptJob(
                $request->validated(),
                $request->user()
            );
            return $this->successResponse($response, 'Job accepted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error accepting job', $e);
        }
    }

    public function acceptJobWithId(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->acceptJobWithId(
                $request->get('job_id'),
                $request->user()
            );
            return $this->successResponse($response, 'Job accepted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error accepting job', $e);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->cancelJobAjax(
                $request->validated(),
                $request->user()
            );
            return $this->successResponse($response, 'Job cancelled successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error cancelling job', $e);
        }
    }
    /**
     * @param Request $request
     * @return mixed
     */

    public function endJob(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->endJob($request->validated());
            return $this->successResponse($response, 'Job ended successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error ending job', $e);
        }
    }

    public function customerNotCall(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->customerNotCall($request->validated());
            return $this->successResponse($response, 'Customer not call recorded');
        } catch (\Exception $e) {
            return $this->errorResponse('Error recording customer not call', $e);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->getPotentialJobs($request->user());
            return $this->successResponse($response);
        } catch (\Exception $e) {
            return $this->errorResponse('Error fetching potential jobs', $e);
        }
    }

    public function distanceFeed(Request $request): JsonResponse
    {
        $data = $request->only(['distance', 'time', 'jobid', 'session_time', 'flagged', 'admincomment', 'manually_handled', 'by_admin']);

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? null;
        $session = $data['session_time'] ?? '';
        $admincomment = $data['admincomment'] ?? '';

        $flagged = $data['flagged'] === 'true' ? 'yes' : 'no';


        if ($flagged === 'yes' && empty($admincomment)) {
            return response()->json(['error' => 'Please, add a comment'], Response::HTTP_BAD_REQUEST);
        }

        $manuallyHandled = $data['manually_handled'] === 'true' ? 'yes' : 'no';
        $byAdmin = $data['by_admin'] === 'true' ? 'yes' : 'no';


        if ($time || $distance) {
            $affectedRows = Distance::where('job_id', $jobid)->update([
                'distance' => $distance,
                'time' => $time,
            ]);
        }

        if ($admincomment || $session || $flagged || $manuallyHandled || $byAdmin) {
            $affectedRows1 = Job::where('id', $jobid)->update([
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manuallyHandled,
                'by_admin' => $byAdmin,
            ]);
        }

        return $this->successResponse(null, 'Distance feed updated successfully');
    }

    public function reopen(BookingRequest $request): JsonResponse
    {
        try {
            $response = $this->repository->reopen($request->validated());
            return $this->successResponse($response, 'Job reopened successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error reopening job', $e);
        }
    }


    public function resendNotifications(BookingRequest $request): JsonResponse
    {
        try {
            $jobId = $request->get('jobid');
            $job = $this->repository->find($jobId);

            if (!$job) {
                return $this->notFoundResponse('Job not found');
            }

            $jobData = $this->repository->jobToData($job);
            $this->repository->sendNotificationTranslator($job, $jobData, '*');

            return $this->successResponse(null, 'Notifications sent successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error resending notifications', $e);
        }
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request): JsonResponse
    {
        try {
            $jobId = $request->get('jobid');
            $job = $this->repository->find($jobId);

            if (!$job) {
                return $this->notFoundResponse('Job not found');
            }

            $this->repository->sendSMSNotificationToTranslator($job);
            return $this->successResponse(null, 'SMS notifications sent successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Error sending SMS notifications', $e);
        }
    }
    private function successResponse($data, ?string $message = null, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if ($message) {
            $response['message'] = $message;
        }

        return response()->json($response, $status);
    }

    private function errorResponse(string $message, ?\Exception $exception = null, int $status = Response::HTTP_INTERNAL_SERVER_ERROR): JsonResponse
    {
        Log::error($message . ($exception ? ': ' . $exception->getMessage() : ''));

        return response()->json([
            'success' => false,
            'message' => $message
        ], $status);
    }

    private function sendUnauthorized(string $message = 'Unauthorized access',  int $status =  Response::HTTP_FORBIDDEN): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], Response::HTTP_NOT_FOUND);
    }
}
