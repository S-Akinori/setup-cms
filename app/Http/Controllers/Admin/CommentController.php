<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommentController extends Controller
{
    public function index(Request $request): View
    {
        $comments = Comment::with('post:id,title,slug,content_type')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.comments.index', compact('comments'));
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'spam'])],
        ]);
        $comment->update($data);
        Audit::record($request, 'comment.updated', $comment, ['status' => $comment->status]);

        return back()->with('success', 'コメントの状態を更新しました。');
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $comment->delete();
        Audit::record($request, 'comment.deleted', $comment);

        return back()->with('success', 'コメントを削除しました。');
    }
}
