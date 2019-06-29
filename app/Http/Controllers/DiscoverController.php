<?php

namespace App\Http\Controllers;

use App\{
  DiscoverCategory,
  Follower,
  Hashtag,
  Profile,
  Status, 
  StatusHashtag, 
  UserFilter
};
use Auth, DB, Cache;
use Illuminate\Http\Request;
use App\Transformer\Api\StatusStatelessTransformer;
use League\Fractal;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;

class DiscoverController extends Controller
{
    protected $fractal;

    public function __construct()
    {
        $this->fractal = new Fractal\Manager();
        $this->fractal->setSerializer(new ArraySerializer());
    }

    public function home(Request $request)
    {
        abort_if(!Auth::check(), 403);
        return view('discover.home');
    }

    public function showTags(Request $request, $hashtag)
    {
        abort_if(!Auth::check(), 403);

        $tag = Hashtag::whereSlug($hashtag)
          ->firstOrFail();

        $page = 1;
        $key = 'discover:tag-'.$tag->id.':page-'.$page;
        $keyMinutes = 15;

        $posts = Cache::remember($key, now()->addMinutes($keyMinutes), function() use ($tag, $request) {
          $tags = StatusHashtag::select('status_id')
            ->whereHashtagId($tag->id)
            ->orderByDesc('id')
            ->take(48)
            ->pluck('status_id');

          return Status::select(
            'id', 
            'uri',
            'caption',
            'rendered',
            'profile_id', 
            'type',
            'in_reply_to_id',
            'reblog_of_id',
            'is_nsfw',
            'scope',
            'local',
            'created_at',
            'updated_at'
          )->whereIn('type', ['photo', 'photo:album', 'video', 'video:album'])
          ->with('media')
          ->whereLocal(true)
          ->whereNull('uri')
          ->whereIn('id', $tags)
          ->whereNull('in_reply_to_id')
          ->whereNull('reblog_of_id')
          ->whereNull('url')
          ->whereNull('uri')
          ->withCount(['likes', 'comments'])
          ->whereIsNsfw(false)
          ->whereVisibility('public')
          ->orderBy('id', 'desc')
          ->get();
        });

        if($posts->count() == 0) {
          abort(404);
        }
        
        return view('discover.tags.show', compact('tag', 'posts'));
    }

    public function showCategory(Request $request, $slug)
    {
      abort_if(!Auth::check(), 403);

      $tag = DiscoverCategory::whereActive(true)
        ->whereSlug($slug)
        ->firstOrFail();

      $posts = Cache::remember('discover:category-'.$tag->id.':posts', now()->addMinutes(15), function() use ($tag) {
          $tagids = $tag->hashtags->pluck('id')->toArray();
          $sids = StatusHashtag::whereIn('hashtag_id', $tagids)->orderByDesc('status_id')->take(500)->pluck('status_id')->toArray();
          $posts = Status::whereScope('public')->whereIn('id', $sids)->whereNull('uri')->whereType('photo')->whereNull('in_reply_to_id')->whereNull('reblog_of_id')->orderByDesc('created_at')->take(39)->get();
          return $posts;
      });
      $tag->posts_count = Cache::remember('discover:category-'.$tag->id.':posts_count', now()->addMinutes(30), function() use ($tag) {
        return $tag->posts()->whereScope('public')->count();
      });
      return view('discover.tags.category', compact('tag', 'posts'));
    }

    public function showPersonal(Request $request)
    {
      abort_if(!Auth::check(), 403);

      $profile = Auth::user()->profile;

      $tags = Cache::remember('profile-'.$profile->id.':hashtags', now()->addMinutes(15), function() use ($profile){
          return $profile->hashtags()->groupBy('hashtag_id')->inRandomOrder()->take(8)->get();
      });
      $following = Cache::remember('profile:following:'.$profile->id, now()->addMinutes(60), function() use ($profile) {
          $res = Follower::whereProfileId($profile->id)->pluck('following_id');
          return $res->push($profile->id)->toArray();
      });
      $posts = Cache::remember('profile-'.$profile->id.':hashtag-posts', now()->addMinutes(5), function() use ($profile, $following) {
          $posts = Status::whereScope('public')->withCount(['likes','comments'])->whereNotIn('profile_id', $following)->whereHas('media')->whereType('photo')->orderByDesc('created_at')->take(39)->get();
          $posts->post_count = Status::whereScope('public')->whereNotIn('profile_id', $following)->whereHas('media')->whereType('photo')->count();
          return $posts;
      });
      return view('discover.personal', compact('posts', 'tags'));
    }

    public function showLoops(Request $request)
    {
      if(config('exp.loops') != true) {
        return redirect('/');
      }
      return view('discover.loops.home');
    }

    public function loopsApi(Request $request)
    {
        abort_if(!config('exp.loops'), 403);
        
        // todo proper pagination, maybe LoopService
        $res = Cache::remember('discover:loops:recent', now()->addHours(1), function() {
          $loops = Status::whereType('video')
                  ->whereScope('public')
                  ->latest()
                  ->take(18)
                  ->get();

          $resource = new Fractal\Resource\Collection($loops, new StatusStatelessTransformer());
          return $this->fractal->createData($resource)->toArray();
        });
        return $res;
    }


    public function loopWatch(Request $request)
    {
        abort_if(!Auth::check(), 403);
        abort_if(!config('exp.loops'), 403);

        $this->validate($request, [
            'id' => 'integer|min:1'
        ]);
        $id = $request->input('id');

        // todo log loops

        return response()->json(200);
    }
}
