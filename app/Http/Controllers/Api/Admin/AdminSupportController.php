<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiderSupportTicket;
use App\Models\RestaurantSupportTicket;
use App\Models\CustomerSupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminSupportController extends Controller
{
    public function tickets()
    {
        $riderTickets = RiderSupportTicket::query()
            ->with('rider:id,name,first_name,last_name,email,phone')
            ->get()
            ->map(function (RiderSupportTicket $ticket) {
                $rider = $ticket->rider;
                $riderName = $rider?->name ?: trim(($rider?->first_name ?? '') . ' ' . ($rider?->last_name ?? ''));

                return $this->ticketRow(
                    $ticket,
                    'Rider',
                    $rider?->id,
                    $riderName !== '' ? $riderName : 'Unknown rider',
                    $rider?->email,
                    $rider?->phone
                );
            });

        $restaurantTickets = RestaurantSupportTicket::query()
            ->with('restaurant:id,restaurant_name,owner_name,email,mobile')
            ->get()
            ->map(function (RestaurantSupportTicket $ticket) {
                $restaurant = $ticket->restaurant;

                return $this->ticketRow(
                    $ticket,
                    'Restaurant',
                    $restaurant?->id,
                    $restaurant?->restaurant_name ?: ($restaurant?->owner_name ?: 'Unknown restaurant'),
                    $restaurant?->email,
                    $restaurant?->mobile
                );
            });

        $customerTickets = CustomerSupportTicket::query()
            ->with(['customer:id,name,phone,email', 'order:id,order_number'])
            ->get()
            ->map(function (CustomerSupportTicket $ticket) {
                $customer = $ticket->customer;
                $customerName = $customer?->name ?: ($customer?->phone ?: 'Customer #' . $ticket->customer_id);

                return $this->ticketRow(
                    $ticket,
                    'Customer',
                    $customer?->id,
                    $customerName,
                    $customer?->email,
                    $customer?->phone
                );
            });

        $tickets = $riderTickets
            ->concat($restaurantTickets)
            ->concat($customerTickets)
            ->sortByDesc(fn (array $ticket) => $ticket['created_at']?->getTimestamp() ?? 0)
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Support tickets fetched successfully.',
            'data' => $tickets,
        ]);
    }

    public function getTicket(string $ticketNumber)
    {
        $ticketType = 'rider';
        $ticket = RiderSupportTicket::where('ticket_number', $ticketNumber)
            ->with('rider:id,name,first_name,last_name,email,phone')
            ->first();

        if (!$ticket) {
            $ticketType = 'restaurant';
            $ticket = RestaurantSupportTicket::where('ticket_number', $ticketNumber)
                ->with('restaurant:id,restaurant_name,owner_name,email,mobile')
                ->first();
        }

        if (!$ticket) {
            $ticketType = 'customer';
            $ticket = CustomerSupportTicket::where('ticket_number', $ticketNumber)
                ->with(['customer:id,name,phone,email', 'order:id,order_number'])
                ->first();
        }

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        $requesterName = 'Unknown';
        $requesterId = null;
        $requesterEmail = null;
        $requesterPhone = null;

        if ($ticketType === 'rider' && $ticket->rider) {
            $requesterId = $ticket->rider->id;
            $requesterName = $ticket->rider->name ?: trim(($ticket->rider->first_name ?? '') . ' ' . ($ticket->rider->last_name ?? ''));
            $requesterEmail = $ticket->rider->email;
            $requesterPhone = $ticket->rider->phone;
        } elseif ($ticketType === 'restaurant' && $ticket->restaurant) {
            $requesterId = $ticket->restaurant->id;
            $requesterName = $ticket->restaurant->restaurant_name ?: ($ticket->restaurant->owner_name ?: 'Unknown restaurant');
            $requesterEmail = $ticket->restaurant->email;
            $requesterPhone = $ticket->restaurant->mobile;
        } elseif ($ticketType === 'customer' && $ticket->customer) {
            $requesterId = $ticket->customer->id;
            $requesterName = $ticket->customer->name ?: ($ticket->customer->phone ?: 'Customer #' . $ticket->customer_id);
            $requesterEmail = $ticket->customer->email;
            $requesterPhone = $ticket->customer->phone;
        }

        $messages = SupportTicketMessage::where('ticket_type', $ticketType)
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'sender_type' => $m->sender_type,
                'message' => $m->message,
                'image_url' => $m->image_path ? asset('storage/' . $m->image_path) : null,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
            ]);

        $data = $this->ticketRow(
            $ticket,
            ucfirst($ticketType),
            $requesterId,
            $requesterName,
            $requesterEmail,
            $requesterPhone
        );
        $data['messages'] = $messages;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function replyToTicket(Request $request, string $ticketNumber)
    {
        $validated = $request->validate([
            'message' => ['required_without:image', 'nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $ticketType = 'rider';
        $ticket = RiderSupportTicket::where('ticket_number', $ticketNumber)->first();

        if (!$ticket) {
            $ticketType = 'restaurant';
            $ticket = RestaurantSupportTicket::where('ticket_number', $ticketNumber)->first();
        }

        if (!$ticket) {
            $ticketType = 'customer';
            $ticket = CustomerSupportTicket::where('ticket_number', $ticketNumber)->first();
        }

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('support', 'public');
        }

        $message = SupportTicketMessage::create([
            'ticket_type' => $ticketType,
            'ticket_id' => $ticket->id,
            'sender_type' => 'admin',
            'message' => $validated['message'] ?? null,
            'image_path' => $imagePath,
        ]);

        $ticket->status = 'replied';
        if (!empty($validated['message'])) {
            $ticket->admin_reply = $validated['message'];
        }
        $ticket->save();

        return response()->json([
            'success' => true,
            'message' => 'Reply sent successfully.',
            'data' => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'message' => $message->message,
                'image_url' => $message->image_path ? asset('storage/' . $message->image_path) : null,
                'created_at' => $message->created_at,
                'ticket_status' => $ticket->status,
            ],
        ]);
    }

    public function updateTicket(Request $request, string $ticketNumber)
    {
        $validated = $request->validate([
            'admin_reply' => ['nullable', 'string', 'max:5000'],
            'status'      => ['nullable', 'in:pending,in_progress,replied,resolved,closed'],
        ]);

        $ticket = RiderSupportTicket::where('ticket_number', $ticketNumber)->first()
            ?? RestaurantSupportTicket::where('ticket_number', $ticketNumber)->first()
            ?? CustomerSupportTicket::where('ticket_number', $ticketNumber)->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        if (array_key_exists('admin_reply', $validated)) {
            $ticket->admin_reply = $validated['admin_reply'];
        }

        if (!empty($validated['status'])) {
            $ticket->status = $validated['status'];
        }

        $ticket->save();

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated successfully.',
            'data'    => [
                'ticket_number' => $ticket->ticket_number,
                'status'        => $ticket->status,
                'admin_reply'   => $ticket->admin_reply,
                'updated_at'    => $ticket->updated_at,
            ],
        ]);
    }

    private function ticketRow(
        Model $ticket,
        string $requesterCategory,
        ?int $requesterId,
        string $requesterName,
        ?string $email = null,
        ?string $phone = null
    ): array {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'requester_category' => $requesterCategory,
            'requester_id' => $requesterId,
            'requester_name' => $requesterName,
            'requester_email' => $email,
            'requester_phone' => $phone,
            'related_order' => isset($ticket->order) && $ticket->order ? ('#' . ($ticket->order->order_number ?: $ticket->order->id)) : null,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'admin_reply' => $ticket->admin_reply,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }
}

