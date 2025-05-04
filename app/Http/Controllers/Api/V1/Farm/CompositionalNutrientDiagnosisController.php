<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\NutrientDiagnosisRequest;
use App\Http\Requests\StoreNutrientDiagnosisRequest;
use App\Http\Resources\NutrientDiagnosisRequestResource;
use App\Services\NutrientDiagnosisResponseService;
use App\Services\NutrientDiagnosisNotificationService;
use App\Services\NutrientDiagnosisRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompositionalNutrientDiagnosisController extends Controller
{
    /**
     * Create a new controller instance.
     * Authorizes all resource actions using the NutrientDiagnosisRequestPolicy.
     */
    public function __construct(
        private NutrientDiagnosisResponseService $responseService,
        private NutrientDiagnosisNotificationService $notificationService,
        private NutrientDiagnosisRequestService $requestService
    ) {
        $this->authorizeResource(NutrientDiagnosisRequest::class, 'request');
        $this->responseService = $responseService;
        $this->notificationService = $notificationService;
        $this->requestService = $requestService;
    }

    /**
     * Display a listing of the nutrient diagnosis requests.
     * For root users, shows all requests for the farm.
     * For regular users, shows only their own requests.
     *
     * @param Farm $farm The farm to get requests for
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Farm $farm)
    {
        $requests = NutrientDiagnosisRequest::with(['samples.field', 'user'])
            ->where('farm_id', $farm->id)
            ->when(!Auth::user()->hasRole('root'), function ($query) {
                $query->where('user_id', Auth::id());
            })
            ->latest()
            ->simplePaginate();

        return NutrientDiagnosisRequestResource::collection($requests);
    }

    /**
     * Display the specified nutrient diagnosis request.
     *
     * @param Farm $farm The farm the request belongs to
     * @param NutrientDiagnosisRequest $request The request to show
     * @return NutrientDiagnosisRequestResource
     */
    public function show(Farm $farm, NutrientDiagnosisRequest $request)
    {
        return new NutrientDiagnosisRequestResource($request->load(['samples.field', 'user']));
    }

    /**
     * Store a newly created nutrient diagnosis request.
     * Creates the request and its associated samples, then notifies root users.
     *
     * @param StoreNutrientDiagnosisRequest $request The validated request data
     * @param Farm $farm The farm to create the request for
     * @return NutrientDiagnosisRequestResource
     */
    public function store(StoreNutrientDiagnosisRequest $request, Farm $farm)
    {
        $diagnosisRequest = $this->requestService->create($farm, $request->validated()['samples']);

        $this->notificationService->notifyRootUsers($diagnosisRequest);

        return new NutrientDiagnosisRequestResource($diagnosisRequest);
    }

    /**
     * Remove the specified nutrient diagnosis request.
     *
     * @param Farm $farm The farm the request belongs to
     * @param NutrientDiagnosisRequest $request The request to delete
     * @return \Illuminate\Http\Response
     */
    public function destroy(Farm $farm, NutrientDiagnosisRequest $request)
    {
        $request->delete();

        return response()->noContent();
    }

    /**
     * Submit a response to a nutrient diagnosis request.
     * Only root users can respond to requests.
     *
     * @param Request $httpRequest The incoming request with response data
     * @param Farm $farm The farm the request belongs to
     * @param NutrientDiagnosisRequest $request The request to respond to
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendResponse(Request $httpRequest, Farm $farm, NutrientDiagnosisRequest $request)
    {
        $this->authorize('respond', $request);

        $httpRequest->validate([
            'description' => 'required|string',
            'attachment' => 'required|file|max:10240', // 10MB max
        ]);

        $this->responseService->handle(
            $request,
            $httpRequest->description,
            $httpRequest->file('attachment')
        );

        return response()->json(['message' => 'Response sent successfully']);
    }

    /**
     * Export all compositional nutrient diagnosis samples for a farm as an Excel file.
     * Only root users can access this endpoint.
     *
     * @param Farm $farm
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(Farm $farm)
    {
        $this->authorize('respond', [\App\Models\NutrientDiagnosisRequest::class, $farm]);
        $export = new \App\Exports\NutrientSamplesExport($farm);
        $filePath = $export->export();
        $filename = __('nutrient_samples_') . $farm->id . '.xlsx';
        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }
}
