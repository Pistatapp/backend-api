<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminTicketController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected TicketAttachmentService $attachmentService
    ) {
        $this->middleware('role:admin|support');
    }

    /**
     * Display a listing of all tickets for support staff.
     */
    public function index(Request $request)
    {
        $query = Ticket::with(['user:id,username,mobile'])
            ->withCount('messages')
            ->recent();

        // Filter by status
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->forUser($request->user_id);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Search by title or user
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('username', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        $tickets = $query->paginate($request->get('per_page', 20));

        return TicketResource::collection($tickets);
    }

    /**
     * Display the specified ticket.
     */
    public function show(Ticket $ticket)
    {
        $ticket->load(['messages.attachments', 'messages.user', 'metadata', 'user']);

        return new TicketDetailResource($ticket);
    }

    /**
     * Reply to a ticket as support staff.
     */
    public function reply(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'required|file|mimes:png,jpg,jpeg,pdf|max:5120',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($request, $ticket, $user, $validated) {
            // Reopen ticket if closed
            if ($ticket->isClosed()) {
                $ticket->reopen();
            }

            // Create support reply message
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $validated['message'],
                'is_support_reply' => true,
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
     * Update the ticket status.
     */
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['waiting', 'answered', 'closed'])],
        ]);

        $ticket->update([
            'status' => $validated['status'],
            'closed_at' => $validated['status'] === 'closed' ? now() : null,
        ]);

        return new TicketDetailResource($ticket->load(['messages.attachments', 'messages.user']));
    }
}

