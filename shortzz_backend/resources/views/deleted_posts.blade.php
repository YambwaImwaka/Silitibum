@extends('include.app')
@section('script')
<script src="{{ asset('assets/script/deleted_posts.js') }}"></script>
<script>
     var swiper = new Swiper(".mySwiper", {
      navigation: {
        nextEl: ".swiper-button-next",
        prevEl: ".swiper-button-prev",
      },
    });
</script>
@endsection
@section('content')

<div class="card">
    <div class="card-header d-flex align-items-center border-bottom">
        <h4 class="card-title mb-0 header-title">
            {{ __('Deleted Content')}}
        </h4>
    </div>
    <div class="card-body">
        <p class="text-muted">
            {{ __('Removed posts are retained here so a takedown can still be reviewed if it is ever disputed. They are permanently deleted automatically once the retention period shown below has passed.')}}
        </p>

        <div class="nav nav-pills mb-2" role="tablist">
            <a class="nav-link active show" data-bs-toggle="pill" href="#v-pills-deleted-posts" role="tab">
                <span class="d-md-block">{{__('Posts')}}</span>
            </a>
            <a class="nav-link" data-bs-toggle="pill" href="#v-pills-deleted-comments" role="tab">
                <span class="d-md-block">{{__('Comments')}}</span>
            </a>
        </div>

        <div class="tab-content mt-2">
            <div class="tab-pane fade active show" id="v-pills-deleted-posts" role="tabpanel">
                <div class="table-responsive">
                    <table id="deletedPostsTable" class="table table-centered table-hover w-100 dt-responsive nowrap mt-3">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Post')}}</th>
                                <th>{{ __('Post User')}}</th>
                                <th>{{ __('Removal')}}</th>
                                <th>{{ __('Deleted At')}}</th>
                                <th>{{ __('Purge After')}}</th>
                                <th style="width: 160px;" class="text-end">{{ __('Action')}}</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="v-pills-deleted-comments" role="tabpanel">
                <div class="table-responsive">
                    <table id="deletedCommentsTable" class="table table-centered table-hover w-100 dt-responsive nowrap mt-3">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('User')}}</th>
                                <th>{{ __('Comment')}}</th>
                                <th>{{ __('Deleted At')}}</th>
                                <th>{{ __('Purge After')}}</th>
                                <th style="width: 120px;" class="text-end">{{ __('Action')}}</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Video/Reel Post Modal --}}
<div id="videoPostModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="standard-modalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="standard-modalLabel">{{ __('View Content')}}</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-hidden="true"></button>
            </div>
            <div class="modal-body">
                <div class="postDescription mb-2" id="videoDescription"></div>
                <video rel="" id="video" width="450" height="450" controls>
                    <source src="" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>
    </div>
</div>

{{-- Image Post Modal --}}
<div id="imagePostModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="standard-modalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="standard-modalLabel">{{ __('View Content')}}</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-hidden="true"></button>
            </div>
            <div class="modal-body">
                <div class="postDescription mb-2" id="imageDescription"></div>
                <div class="swiper mySwiper postImageSwiper">
                    <div class="swiper-wrapper" id="imgPostSwiperWrapper">
                    </div>
                    <div class="swiper-button-next"></div>
                    <div class="swiper-button-prev"></div>
                  </div>
            </div>
        </div>
    </div>
</div>

{{-- Text Post Modal --}}
<div id="textPostModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="standard-modalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="standard-modalLabel">{{ __('View Content')}}</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-hidden="true"></button>
            </div>
            <div class="modal-body">
                <div class="postDescription mb-2" id="textDescription"></div>
            </div>
        </div>
    </div>
</div>

@endsection
