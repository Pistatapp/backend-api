<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\TestNotification;
use App\Events\TestEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Notification;

class TestNotificationController extends Controller
{
    /**
     * Test Firebase notifications, database notifications, and WebSocket broadcasting
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testNotifications(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:1000',
            'test_types' => 'array',
            'test_types.*' => 'in:firebase,database,websocket_private,websocket_public'
        ]);

        $title = $request->input('title');
        $content = $request->input('content');
        $testTypes = $request->input('test_types', ['firebase', 'database', 'websocket_private', 'websocket_public']);

        $user = $request->user();
        $results = [];

        // Test Firebase Notification
        if (in_array('firebase', $testTypes)) {
            try {
                $notification = new TestNotification($title, $content, 'firebase');
                $user->notify($notification);
                $results['firebase'] = [
                    'status' => 'success',
                    'message' => 'Firebase notification sent successfully'
                ];
            } catch (\Exception $e) {
                $results['firebase'] = [
                    'status' => 'error',
                    'message' => 'Firebase notification failed: ' . $e->getMessage()
                ];
            }
        }

        // Test Database Notification
        if (in_array('database', $testTypes)) {
            try {
                $notification = new TestNotification($title, $content, 'database');
                $user->notify($notification);
                $results['database'] = [
                    'status' => 'success',
                    'message' => 'Database notification created successfully'
                ];
            } catch (\Exception $e) {
                $results['database'] = [
                    'status' => 'error',
                    'message' => 'Database notification failed: ' . $e->getMessage()
                ];
            }
        }

        // Test WebSocket Private Channel
        if (in_array('websocket_private', $testTypes)) {
            try {
                $event = new TestEvent($title, $content, 'private', $user->id);
                broadcast($event)->toOthers();
                $results['websocket_private'] = [
                    'status' => 'success',
                    'message' => 'Private WebSocket event broadcasted successfully',
                    'channel' => 'users.' . $user->id
                ];
            } catch (\Exception $e) {
                $results['websocket_private'] = [
                    'status' => 'error',
                    'message' => 'Private WebSocket broadcast failed: ' . $e->getMessage()
                ];
            }
        }

        // Test WebSocket Public Channel
        if (in_array('websocket_public', $testTypes)) {
            try {
                $event = new TestEvent($title, $content, 'public', $user->id);
                broadcast($event);
                $results['websocket_public'] = [
                    'status' => 'success',
                    'message' => 'Public WebSocket event broadcasted successfully',
                    'channel' => 'test-channel'
                ];
            } catch (\Exception $e) {
                $results['websocket_public'] = [
                    'status' => 'error',
                    'message' => 'Public WebSocket broadcast failed: ' . $e->getMessage()
                ];
            }
        }

        return response()->json([
            'message' => 'Notification tests completed',
            'user_id' => $user->id,
            'test_data' => [
                'title' => $title,
                'content' => $content
            ],
            'results' => $results
        ]);
    }

    /**
     * Test individual notification type
     *
     * @param \Illuminate\Http\Request $request
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function testSingleNotification(Request $request, $type)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:1000'
        ]);

        $title = $request->input('title');
        $content = $request->input('content');
        $user = $request->user();

        switch ($type) {
            case 'firebase':
                return $this->testFirebaseNotification($user, $title, $content);
            case 'database':
                return $this->testDatabaseNotification($user, $title, $content);
            case 'websocket-private':
                return $this->testPrivateWebSocket($user, $title, $content);
            case 'websocket-public':
                return $this->testPublicWebSocket($user, $title, $content);
            default:
                return response()->json(['error' => 'Invalid test type'], 400);
        }
    }

    private function testFirebaseNotification($user, $title, $content)
    {
        try {
            $notification = new TestNotification($title, $content, 'firebase');
            $user->notify($notification);

            return response()->json([
                'status' => 'success',
                'message' => 'Firebase notification sent successfully',
                'user_id' => $user->id,
                'fcm_token_exists' => !empty($user->fcm_token)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Firebase notification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function testDatabaseNotification($user, $title, $content)
    {
        try {
            $notification = new TestNotification($title, $content, 'database');
            $user->notify($notification);

            return response()->json([
                'status' => 'success',
                'message' => 'Database notification created successfully',
                'user_id' => $user->id,
                'notification_count' => $user->unreadNotifications()->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database notification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function testPrivateWebSocket($user, $title, $content)
    {
        try {
            $event = new TestEvent($title, $content, 'private', $user->id);
            broadcast($event)->toOthers();

            return response()->json([
                'status' => 'success',
                'message' => 'Private WebSocket event broadcasted successfully',
                'user_id' => $user->id,
                'channel' => 'users.' . $user->id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Private WebSocket broadcast failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function testPublicWebSocket($user, $title, $content)
    {
        try {
            $event = new TestEvent($title, $content, 'public', $user->id);
            broadcast($event);

            return response()->json([
                'status' => 'success',
                'message' => 'Public WebSocket event broadcasted successfully',
                'user_id' => $user->id,
                'channel' => 'test-channel'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Public WebSocket broadcast failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
