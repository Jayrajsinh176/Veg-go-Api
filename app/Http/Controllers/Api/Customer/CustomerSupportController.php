<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerSupportTicket;
use App\Models\RestaurantOrder;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerSupportController extends Controller
{
    public function createTicket(Request $request)
    {
        $validated = $request->validate([
            'category'    => ['required', 'string', 'max:100'],
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'order_id'    => ['nullable', 'integer', 'exists:restaurant_orders,id'],
            'image'       => ['nullable', 'image', 'max:2048'],
        ]);

        $customer = $request->user();

        // If order_id provided, verify it belongs to this customer
        $orderId = null;
        if (!empty($validated['order_id'])) {
            $orderBelongs = RestaurantOrder::where('id', $validated['order_id'])
                ->where('customer_id', $customer->id)
                ->exists();

            if (!$orderBelongs) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected order does not belong to your account.',
                ], 422);
            }
            $orderId = $validated['order_id'];
        }

        $ticket = CustomerSupportTicket::create([
            'customer_id'   => $customer->id,
            'order_id'      => $orderId,
            'ticket_number' => $this->generateTicketNumber(),
            'category'      => $validated['category'],
            'subject'       => $validated['subject'],
            'description'   => $validated['description'],
            'status'        => 'pending',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('support', 'public');
        }

        SupportTicketMessage::create([
            'ticket_type' => 'customer',
            'ticket_id'   => $ticket->id,
            'sender_type' => 'user',
            'message'     => $validated['description'],
            'image_path'  => $imagePath,
        ]);

        app(\App\Services\Notification\AdminNotificationService::class)
            ->supportTicketCreated($ticket, 'customer', $customer->name ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'data'    => $ticket,
        ], 201);
    }

    public function ticketList(Request $request)
    {
        $tickets = CustomerSupportTicket::where('customer_id', $request->user()->id)
            ->with('order:id,order_number')
            ->latest()
            ->get()
            ->map(fn (CustomerSupportTicket $ticket) => [
                'id'            => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'category'      => $ticket->category,
                'subject'       => $ticket->subject,
                'status'        => $ticket->status,
                'related_order' => $ticket->order ? ('#' . ($ticket->order->order_number ?: $ticket->order->id)) : null,
                'created_at'    => $ticket->created_at?->format('d M Y, h:i A'),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $tickets,
        ]);
    }

    public function ticketDetails(Request $request, CustomerSupportTicket $ticket)
    {
        if ($ticket->customer_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        $ticket->load(['order:id,order_number', 'messages']);

        $messages = $ticket->messages->map(fn (SupportTicketMessage $msg) => [
            'id'          => $msg->id,
            'sender_type' => $msg->sender_type,
            'message'     => $msg->message,
            'image_url'   => $msg->image_path ? asset('storage/' . $msg->image_path) : null,
            'created_at'  => $msg->created_at,
            'updated_at'  => $msg->updated_at,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'category'      => $ticket->category,
                'subject'       => $ticket->subject,
                'description'   => $ticket->description,
                'status'        => $ticket->status,
                'related_order' => $ticket->order ? ('#' . ($ticket->order->order_number ?: $ticket->order->id)) : null,
                'admin_reply'   => $ticket->admin_reply,
                'created_at'    => $ticket->created_at,
                'updated_at'    => $ticket->updated_at,
                'messages'      => $messages,
            ],
        ]);
    }

    public function replyToTicket(Request $request, CustomerSupportTicket $ticket)
    {
        if ($ticket->customer_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is already resolved or closed and cannot receive new replies.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => ['required_without:image', 'nullable', 'string', 'max:5000'],
            'image'   => ['nullable', 'image', 'max:2048'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('support', 'public');
        }

        $message = SupportTicketMessage::create([
            'ticket_type' => 'customer',
            'ticket_id'   => $ticket->id,
            'sender_type' => 'user',
            'message'     => $validated['message'] ?? null,
            'image_path'  => $imagePath,
        ]);

        $ticket->status = 'in_progress';
        $ticket->save();

        return response()->json([
            'success' => true,
            'message' => 'Reply sent successfully.',
            'data'    => [
                'id'            => $message->id,
                'sender_type'   => $message->sender_type,
                'message'       => $message->message,
                'image_url'     => $message->image_path ? asset('storage/' . $message->image_path) : null,
                'created_at'    => $message->created_at,
                'ticket_status' => $ticket->status,
            ],
        ]);
    }

    private function generateTicketNumber(): string
    {
        do {
            $ticketNumber = 'VGC' . strtoupper(Str::random(3));
        } while (CustomerSupportTicket::where('ticket_number', $ticketNumber)->exists());

        return $ticketNumber;
    }
}

