<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportErrorRequest;
use App\Http\Requests\SendTicketMessageRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Resources\TicketAttachmentResource;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketMetadata;
use App\Services\TicketAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function __construct(
        protected TicketAttachmentService $attachmentService
    ) {}
    /**
     * Display a listing of the user's tickets.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ticket::forUser($user->id)
            ->withCount('messages')
            ->recent();

        // Filter by status if provided
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        $tickets = $query->paginate($request->get('per_page', 20));

        return TicketResource::collection($tickets);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(StoreTicketRequest $request)
    {
        $user = $request->user();

        return DB::transaction(function () use ($request, $user) {
            // Create ticket
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'title' => $this->generateTitle($request->message),
                'status' => 'waiting',
            ]);

            // Create first message
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'is_support_reply' => false,
            ]);

            // Handle attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $this->attachmentService->storeAttachment($file, $message);
                }
            }

            return new TicketDetailResource($ticket->load(['messages.attachments', 'metadata']));
        });
    }

    /**
     * Display the specified ticket.
     */
    public function show(Request $request, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $ticket->load(['messages.attachments', 'messages.user', 'metadata']);

        return new TicketDetailResource($ticket);
    }

    /**
     * Send a message to an existing ticket.
     */
    public function sendMessage(SendTicketMessageRequest $request, Ticket $ticket)
    {
        $this->authorize('reply', $ticket);

        $user = $request->user();
        $isSupport = $user->hasRole(['admin', 'support']);

        return DB::transaction(function () use ($request, $ticket, $user, $isSupport) {
            // Reopen ticket if closed
            if ($ticket->isClosed()) {
                $ticket->reopen();
            }

            // Create message
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'is_support_reply' => $isSupport,
            ]);

            // Handle attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $this->attachmentService->storeAttachment($file, $message);
                }
            }

            // Observer will handle status updates and notifications

            return new TicketDetailResource($ticket->fresh()->load(['messages.attachments', 'messages.user']));
        });
    }

    /**
     * Close a ticket.
     */
    public function closeTicket(Request $request, Ticket $ticket)
    {
        $this->authorize('close', $ticket);

        $ticket->close();

        return new TicketDetailResource($ticket->load(['messages.attachments', 'messages.user']));
    }

    /**
     * Report an application error.
     */
    public function reportError(ReportErrorRequest $request)
    {
        $user = $request->user();

        return DB::transaction(function () use ($request, $user) {
            // Create ticket with error metadata
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'title' => __('Error Report') . ' - ' . ($request->page_path ?? __('Unknown Location')),
                'status' => 'waiting',
                'last_reply_by' => 'user',
                'last_reply_at' => now(),
            ]);

            // Create metadata
            TicketMetadata::create([
                'ticket_id' => $ticket->id,
                'error_message' => $request->error_message,
                'error_trace' => $request->error_trace,
                'page_path' => $request->page_path,
                'app_version' => $request->app_version,
                'device_model' => $request->device_model,
                'occurred_at' => $request->occurred_at ?? now(),
            ]);

            // Create message with optional user message
            $messageText = $request->message ?? __('An error occurred in the application.');
            $messageText .= "\n\n" . __('Error Details:') . "\n" . $request->error_message;

            TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $messageText,
                'is_support_reply' => false,
            ]);

            return new TicketDetailResource($ticket->load(['messages', 'metadata']));
        });
    }

    /**
     * Download a ticket attachment.
     */
    public function downloadAttachment(Request $request, TicketAttachment $attachment)
    {
        // Get the ticket through the message
        $ticket = $attachment->ticketMessage->ticket;

        // Check authorization
        $this->authorize('view', $ticket);

        $filePath = storage_path('app/' . $attachment->file_path);

        if (!file_exists($filePath)) {
            abort(404, __('File not found.'));
        }

        return response()->download($filePath, $attachment->file_name);
    }


    /**
     * Generate a title from the message.
     */
    protected function generateTitle(string $message): string
    {
        $title = Str::limit(strip_tags($message), 50);
        return $title ?: __('New Ticket');
    }
}

