<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RestaurantSupportController extends Controller
{
    public function createTicket(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $restaurant = $request->user();
        $ticket = RestaurantSupportTicket::create([
            'restaurant_id' => $restaurant->id,
            'ticket_number' => $this->generateTicketNumber(),
            'category' => $validated['category'],
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'status' => 'pending',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('support', 'public');
        }

        SupportTicketMessage::create([
            'ticket_type' => 'restaurant',
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'message' => $validated['description'],
            'image_path' => $imagePath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'data' => $ticket,
        ], 201);
    }

    public function ticketList(Request $request)
    {
        $tickets = RestaurantSupportTicket::where('restaurant_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (RestaurantSupportTicket $ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'category' => $ticket->category,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->format('d M Y, h:i A'),
            ]);

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    public function ticketDetails(Request $request, RestaurantSupportTicket $ticket)
    {
        if ($ticket->restaurant_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        $ticket->load('messages');

        $messages = $ticket->messages->map(fn ($message) => [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'message' => $message->message,
            'image_url' => $message->image_path ? asset('storage/' . $message->image_path) : null,
            'created_at' => $message->created_at,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'category' => $ticket->category,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'status' => $ticket->status,
                'admin_reply' => $ticket->admin_reply,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at,
                'messages' => $messages,
            ],
        ]);
    }
    
    public function replyToTicket(Request $request, RestaurantSupportTicket $ticket)
    {
        $validated = $request->validate([
            'message' => ['required_without:image', 'nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $restaurant = $request->user();

        if ($ticket->restaurant_id !== $restaurant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        if (in_array($ticket->status, ['resolved', 'closed'])) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is already resolved or closed and cannot receive new replies.',
            ], 422);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('support', 'public');
        }

        $message = SupportTicketMessage::create([
            'ticket_type' => 'restaurant',
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'message' => $validated['message'] ?? null,
            'image_path' => $imagePath,
        ]);

        $ticket->update(['status' => 'in_progress']);

        return response()->json([
            'success' => true,
            'message' => 'Reply sent successfully.',
            'data' => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'message' => $message->message,
                'image_url' => $message->image_path ? asset('storage/' . $message->image_path) : null,
                'created_at' => $message->created_at,
            ]
        ]);
    }

    private function generateTicketNumber(): string
    {
        do {
            $ticketNumber = 'VGR' . strtoupper(Str::random(3));
        } while (RestaurantSupportTicket::where('ticket_number', $ticketNumber)->exists());

        return $ticketNumber;
    }
}
