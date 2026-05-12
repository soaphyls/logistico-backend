<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        $query = Task::with(['assignedTo', 'assignedBy']);

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            $query->where('assigned_to', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $tasks = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($tasks);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            return $this->error('You do not have permission to create tasks', 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'required|exists:users,id',
            'related_to_type' => 'nullable|string',
            'related_to_id' => 'nullable|integer',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
        ]);

        $validated['assigned_by'] = auth()->id();

        $task = Task::create($validated);

        Notification::create([
            'user_id' => $validated['assigned_to'],
            'title' => 'New Task Assigned',
            'message' => "You have been assigned a new task: {$task->title}",
            'type' => 'task',
            'related_to_type' => Task::class,
            'related_to_id' => $task->id,
        ]);

        return $this->success($task, 'Task created successfully', 201);
    }

    public function show(Task $task)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service']) && $task->assigned_to !== $user->id) {
            return $this->error('Access denied', 403);
        }

        $task->load(['assignedTo', 'assignedBy']);

        return $this->success($task);
    }

    public function update(Request $request, Task $task)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            return $this->error('You do not have permission to update tasks', 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'sometimes|exists:users,id',
            'related_to_type' => 'nullable|string',
            'related_to_id' => 'nullable|integer',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
        ]);

        $task->update($validated);

        return $this->success($task, 'Task updated successfully');
    }

    public function destroy(Task $task)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service'])) {
            return $this->error('You do not have permission to delete tasks', 403);
        }

        $task->delete();

        return $this->success(null, 'Task deleted successfully');
    }

    public function updateStatus(Request $request, Task $task)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service']) && $task->assigned_to !== $user->id) {
            return $this->error('Access denied', 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,cancelled',
        ]);

        $task->update(['status' => $validated['status']]);

        if ($validated['status'] === 'completed') {
            $task->update(['completed_at' => now()]);
        }

        return $this->success($task, 'Task status updated');
    }

    public function myTasks(Request $request)
    {
        $query = Task::with(['assignedBy'])
            ->where('assigned_to', auth()->id());

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $tasks = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($tasks);
    }
}
