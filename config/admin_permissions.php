<?php

/*
|--------------------------------------------------------------------------
| Super Admin Panel - Roles & Permissions
|--------------------------------------------------------------------------
|
| Module ids and action names must match the Roles & Permissions page
| (Frontend/src/services/permissions.js). A role's permissions are stored
| as { moduleId: [actions] } on the admin_roles table.
|
*/

$actions = ['View', 'Create', 'Edit', 'Approve', 'Reject', 'Suspend', 'Delete', 'Restore', 'Export'];

return [

    'actions' => $actions,

    'modules' => [
        'users' => 'Users',
        'restaurants' => 'Restaurants',
        'restaurantApplications' => 'Restaurant Applications',
        'menu' => 'Menu & Food Items',
        'restaurantOffers' => 'Restaurant Offers',
        'deliveryPartners' => 'Delivery Partners',
        'deliveryApplications' => 'Delivery Applications',
        'riderLocation' => 'Rider Location / Availability',
        'orders' => 'Orders',
        'payments' => 'Payments & Transactions',
        'finance' => 'Finance',
        'marketing' => 'Marketing',
        'support' => 'Support & Escalations',
        'notifications' => 'Notifications',
        'reports' => 'Reports & Analytics',
        'activityLogs' => 'Activity Logs',
        'systemSettings' => 'System Settings',
    ],

];
