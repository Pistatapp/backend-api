<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TicketPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Any authenticated user can view their own tickets
        // Support staff can view all tickets
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        // User can view their own ticket
        if ($user->id === $ticket->user_id) {
            return true;
        }

        // Support staff can view any ticket
        return $user->hasRole(['admin', 'support']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Any authenticated user can create tickets
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        // User can update their own ticket (for reopening/closing)
        if ($user->id === $ticket->user_id) {
            return true;
        }

        // Support staff can update any ticket
        return $user->hasRole(['admin', 'support']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        // Only support staff can delete tickets
        return $user->hasRole(['admin', 'support']);
    }

    /**
     * Determine whether the user can reply to the ticket.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        // User can reply to their own ticket
        if ($user->id === $ticket->user_id) {
            return true;
        }

        // Support staff can reply to any ticket
        return $user->hasRole(['admin', 'support']);
    }

    /**
     * Determine whether the user can close the ticket.
     */
    public function close(User $user, Ticket $ticket): bool
    {
        // User can close their own ticket
        if ($user->id === $ticket->user_id) {
            return true;
        }

        // Support staff can close any ticket
        return $user->hasRole(['admin', 'support']);
    }
}

