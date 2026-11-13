<?php 
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Membership\MembershipAccessService;

class ResearchResource extends JsonResource
{
    public function toArray($request)
    {
        $isPremium = $request->user()
            && app(MembershipAccessService::class)->snapshot($request->user())['effective_package'] === 'premium';
        return [
            'id' => (int) $this->id,
            'fun_facts' => $this->fun_facts,
            'summary' => $this->summary,
            'video_link' => $isPremium ? $this->video_link : null,
            'video_type' => $isPremium ? $this->video_type : null,
            'full_content' => $isPremium ? $this->full_content : null,
            'files' => $isPremium ? $this->files : null,
        ];
    }
}
