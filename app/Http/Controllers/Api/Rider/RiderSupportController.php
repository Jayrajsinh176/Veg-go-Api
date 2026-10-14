<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\RiderSupportTicket;
use App\Models\SupportTicketMessage;

class RiderSupportController extends Controller
{
    public function createTicket(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category' => 'required|string|max:100',
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $rider = $request->user();

        $ticket = RiderSupportTicket::create([
            'rider_id' => $rider->id,
            'ticket_number' => $this->generateTicketNumber(),
            'category' => $request->category,
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'pending',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('support', 'public');
        }

        SupportTicketMessage::create([
            'ticket_type' => 'rider',
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'message' => $request->description,
            'image_path' => $imagePath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'data' => $ticket,
        ]);
    }

    public function ticketList(Request $request)
    {
        $rider = $request->user();

        $tickets = RiderSupportTicket::where('rider_id', $rider->id)
            ->latest()
            ->get()
            ->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'category' => $ticket->category,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'created_at' => $ticket->created_at->format('d M Y, h:i A'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    public function ticketDetails(Request $request, RiderSupportTicket $ticket)
    {
        $rider = $request->user();

        if ($ticket->rider_id != $rider->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }
        
        $ticket->load('messages');

        $messages = $ticket->messages->map(function ($message) {
            return [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'message' => $message->message,
                'image_url' => $message->image_path ? asset('storage/' . $message->image_path) : null,
                'created_at' => $message->created_at,
            ];
        });

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
            ]
        ]);
    }

    public function replyToTicket(Request $request, RiderSupportTicket $ticket)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required_without:image|nullable|string|max:5000',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $rider = $request->user();

        if ($ticket->rider_id != $rider->id) {
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
            'ticket_type' => 'rider',
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'message' => $request->message,
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
            $ticketNumber = 'VGD' . strtoupper(Str::random(3));
        } while (RiderSupportTicket::where('ticket_number', $ticketNumber)->exists());

        return $ticketNumber;
    }
}