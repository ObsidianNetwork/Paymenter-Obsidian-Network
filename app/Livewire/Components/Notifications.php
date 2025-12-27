<?php

namespace App\Livewire\Components;

use App\Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

class Notifications extends Component
{
    #[Computed, On('notification-added')]
    public function notifications()
    {
        return Auth::user()->notifications()->orderBy('created_at', 'desc')->limit(5)->get();
    }

    public function goToNotification($id)
    {
        $notification = Auth::user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return;
        }

        $notification->markAsRead();

        if ($notification->url) {
            return $this->redirect($notification->url, true);
        }
    }

    public function markAsRead($id)
    {
        $notification = Auth::user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return;
        }

        $notification->markAsRead();
    }

    public function markAsUnread($id)
    {
        $notification = Auth::user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return;
        }

        $notification->markAsUnread();
    }

    public function markAllAsRead()
    {
        Auth::user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function deleteNotification($id)
    {
        $notification = Auth::user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return;
        }

        $notification->delete();
    }

    public function deleteAllNotifications()
    {
        Auth::user()->notifications()->delete();
    }

    public function render()
    {
        return view('components.notifications');
    }
}
