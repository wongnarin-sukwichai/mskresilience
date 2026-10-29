<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommunityRequest;
use App\Models\Community;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CommunityManagementController extends Controller
{
    /**
     * List all communities, in the client's COMMUNITY_ID order.
     */
    public function index(): Response
    {
        return Inertia::render('admin/Communities', [
            'communities' => Community::query()->orderBy('code')->get(),
        ]);
    }

    /**
     * Create a new community. Its map boundary is not managed here — see CLAUDE.md's จัดการชุมชน note.
     */
    public function store(CommunityRequest $request): RedirectResponse
    {
        Community::create($request->validated());

        return back()->with('status', 'เพิ่มชุมชนเรียบร้อยแล้ว');
    }

    /**
     * Update an existing community's name. The code is immutable — see CommunityRequest::rules().
     */
    public function update(CommunityRequest $request, Community $community): RedirectResponse
    {
        $community->update($request->validated());

        return back()->with('status', 'บันทึกข้อมูลชุมชนเรียบร้อยแล้ว');
    }

    /**
     * Delete a community. Nothing references communities yet; once assessment/event tables exist,
     * this must refuse (or the FK must restrict) when the community still has data attached.
     */
    public function destroy(Community $community): RedirectResponse
    {
        $community->delete();

        return back()->with('status', 'ลบชุมชนเรียบร้อยแล้ว');
    }
}
