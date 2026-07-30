<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\RiderSupportTicket;

class RiderSupportController extends Controller
{
    public function createTicket(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category' => 'required|string|max:100',
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
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
            'ticket_number' => 'VGT' . str_pad(RiderSupportTicket::count() + 1, 6, '0', STR_PAD_LEFT),
            'category' => $request->category,
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'pending',
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
        ]
    ]);
}

}