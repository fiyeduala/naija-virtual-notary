{{--
    Comments on an article.

    Every comment body on this page is rendered with {!! nl2br(e($body)) !!} —
    ESCAPE FIRST, then turn the newlines into breaks. That order is the whole
    of the safety here: e() renders any markup a stranger typed as visible
    text, and nl2br afterwards only ever adds <br> tags of our own. Writing it
    the other way round, or reaching for the HtmlSanitizer that Post::body
    uses, would hand a public write surface the one capability it must not
    have. The sanitiser exists to let some tags through; that is right for an
    article an admin wrote and wrong for this.
--}}
<section id="comments" class="section-sm comments-block">
    <div class="article-wrap">
        <h3 class="comments-head">
            {{ $comments->count() === 1 ? '1 comment' : $comments->count() . ' comments' }}
        </h3>

        @if (session('comment_status'))
            <p class="comment-note">{{ session('comment_status') }}</p>
        @endif

        @forelse ($comments as $comment)
            <article class="comment">
                <div class="comment-meta">
                    <strong>{{ $comment->author_name }}</strong>
                    @if ($comment->fromAccount())
                        <span class="comment-tag">Verified account</span>
                    @endif
                    <span class="dot"></span>
                    <time datetime="{{ $comment->created_at->toIso8601String() }}">
                        {{ $comment->created_at->format('j M Y') }}
                    </time>
                </div>

                <div class="comment-body">{!! nl2br(e($comment->body)) !!}</div>

                @foreach ($comment->replies as $reply)
                    <article class="comment comment-reply">
                        <div class="comment-meta">
                            <strong>{{ $reply->author_name }}</strong>
                            @if ($reply->fromAccount())
                                <span class="comment-tag">Verified account</span>
                            @endif
                            <span class="dot"></span>
                            <time datetime="{{ $reply->created_at->toIso8601String() }}">
                                {{ $reply->created_at->format('j M Y') }}
                            </time>
                        </div>

                        <div class="comment-body">{!! nl2br(e($reply->body)) !!}</div>
                    </article>
                @endforeach

                <button type="button" class="comment-reply-btn" data-reply-to="{{ $comment->id }}"
                        data-reply-name="{{ $comment->author_name }}">
                    Reply
                </button>
            </article>
        @empty
            <p class="comment-empty">No comments yet. Be the first to say something.</p>
        @endforelse

        <form method="POST" action="{{ route('blog.comment', $post) }}" class="comment-form" id="comment-form">
            @csrf

            <h4 class="comment-form-head" id="comment-form-head">Leave a comment</h4>

            {{-- Set by the Reply buttons, cleared by "cancel". --}}
            <input type="hidden" name="parent_id" id="comment-parent" value="{{ old('parent_id') }}">

            <p class="comment-replying" id="comment-replying" hidden>
                Replying to <strong id="comment-replying-name"></strong>
                <button type="button" id="comment-cancel-reply">cancel</button>
            </p>

            {{--
                The honeypot. Hidden from a person and left empty by them; the
                FormRequest rejects any request that filled it in. Kept out of
                the tab order and hidden from screen readers so it is invisible
                to somebody using one, rather than merely invisible on screen.
            --}}
            <div class="comment-hp" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            {{-- An encrypted render time, so the minimum-delay check cannot be
                 back-dated by whoever is posting. --}}
            <input type="hidden" name="posted_at" value="{{ Crypt::encryptString((string) time()) }}">

            @auth
                <p class="comment-as">
                    Commenting as <strong>{{ auth()->user()->full_name }}</strong>.
                </p>
            @else
                <div class="comment-fields">
                    <label>
                        Your name
                        <input type="text" name="author_name" value="{{ old('author_name') }}"
                               maxlength="120" required>
                    </label>
                    <label>
                        Your email
                        <input type="email" name="author_email" value="{{ old('author_email') }}"
                               maxlength="190" required>
                        <small>Not published. We only use it to reply to you.</small>
                    </label>
                </div>
            @endauth

            <label class="comment-body-field">
                Your comment
                <textarea name="body" id="comment-body" rows="5" maxlength="3000" required>{{ old('body') }}</textarea>
            </label>

            @error('body')
                <p class="comment-error">{{ $message }}</p>
            @enderror
            @error('website')
                <p class="comment-error">{{ $message }}</p>
            @enderror
            @error('author_name')
                <p class="comment-error">{{ $message }}</p>
            @enderror
            @error('author_email')
                <p class="comment-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn btn-primary">Post comment</button>

            <p class="comment-smallprint">
                Comments are read by us before they appear.
            </p>
        </form>
    </div>
</section>
