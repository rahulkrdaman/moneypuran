"use client";
import { useState, useEffect } from "react";
import { MessageCircle, Send, ChevronDown, ChevronUp, AlertCircle, CheckCircle, User } from "lucide-react";

interface Comment {
  id: string; content: string; authorName: string; createdAt: string;
  replies?: Comment[];
}

function timeAgo(date: string) {
  const s = (Date.now() - new Date(date).getTime()) / 1000;
  if (s < 60) return "just now";
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

function CommentCard({ comment, postId, depth = 0 }: { comment: Comment; postId: string; depth?: number }) {
  const [showReply, setShowReply] = useState(false);
  const [showReplies, setShowReplies] = useState(true);
  const [replyContent, setReplyContent] = useState("");
  const [name, setName] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  async function handleReply() {
    if (!replyContent.trim()) return;
    setSubmitting(true);
    await fetch("/api/comments", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ postId, content: replyContent, authorName: name || "Anonymous", parentId: comment.id }),
    });
    setSubmitted(true); setSubmitting(false); setReplyContent(""); setShowReply(false);
  }

  return (
    <div className={`${depth > 0 ? "ml-4 sm:ml-8 border-l-2 border-border pl-4" : ""}`}>
      <div className="flex gap-3">
        <div className="flex-shrink-0 h-8 w-8 rounded-full bg-muted flex items-center justify-center text-sm font-semibold text-muted-foreground">
          {comment.authorName?.[0]?.toUpperCase() || <User className="h-4 w-4" />}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-medium text-sm">{comment.authorName || "Anonymous"}</span>
            <span className="text-xs text-muted-foreground">{timeAgo(comment.createdAt)}</span>
          </div>
          <p className="text-sm text-foreground/90 mt-1 leading-relaxed">{comment.content}</p>
          {depth === 0 && (
            <button onClick={() => setShowReply(!showReply)}
              className="mt-2 text-xs text-muted-foreground hover:text-brand-600 font-medium transition-colors">
              Reply
            </button>
          )}
        </div>
      </div>

      {showReply && (
        <div className="mt-3 ml-11 space-y-2">
          {submitted ? (
            <div className="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
              <CheckCircle className="h-4 w-4" /> Reply submitted for moderation.
            </div>
          ) : (
            <>
              <input value={name} onChange={e => setName(e.target.value)} className="input text-sm" placeholder="Your name (optional)" />
              <textarea value={replyContent} onChange={e => setReplyContent(e.target.value)}
                className="input text-sm resize-none" rows={2} placeholder="Write a reply..." />
              <div className="flex gap-2">
                <button onClick={handleReply} disabled={submitting || !replyContent.trim()}
                  className="btn-primary text-xs py-1.5">{submitting ? "Posting..." : "Post Reply"}</button>
                <button onClick={() => setShowReply(false)} className="btn-secondary text-xs py-1.5">Cancel</button>
              </div>
            </>
          )}
        </div>
      )}

      {comment.replies && comment.replies.length > 0 && (
        <div className="mt-3">
          <button onClick={() => setShowReplies(!showReplies)}
            className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground ml-11 mb-2">
            {showReplies ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
            {comment.replies.length} {comment.replies.length === 1 ? "reply" : "replies"}
          </button>
          {showReplies && (
            <div className="space-y-4">
              {comment.replies.map(r => <CommentCard key={r.id} comment={r} postId={postId} depth={depth + 1} />)}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function CommentSection({ postId }: { postId: string }) {
  const [comments, setComments] = useState<Comment[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [content, setContent] = useState("");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [status, setStatus] = useState<"idle" | "pending" | "error">("idle");

  useEffect(() => {
    fetch(`/api/comments?postId=${postId}&status=APPROVED&limit=30`)
      .then(r => r.json()).then(d => { setComments(d.data || []); setTotal(d.pagination?.total || 0); setLoading(false); });
  }, [postId]);

  async function handleSubmit() {
    if (!content.trim()) return;
    setSubmitting(true);
    try {
      const res = await fetch("/api/comments", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ postId, content, authorName: name || "Anonymous", authorEmail: email || undefined }),
      });
      const d = await res.json();
      if (d.success) {
        setStatus(d.pending ? "pending" : "idle");
        if (!d.pending) setComments(c => [d.data, ...c]);
        setContent(""); setName(""); setEmail("");
      } else setStatus("error");
    } catch { setStatus("error"); }
    setSubmitting(false);
  }

  return (
    <section className="mt-10 pt-8 border-t border-border">
      <h3 className="text-xl font-heading font-bold mb-6 flex items-center gap-2">
        <MessageCircle className="h-5 w-5 text-brand-600" />
        Comments ({total})
      </h3>

      {/* Post Comment */}
      <div className="card p-5 mb-6">
        <h4 className="font-semibold text-sm mb-3">Leave a Comment</h4>
        {status === "pending" ? (
          <div className="flex items-start gap-3 p-4 rounded-lg bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 text-sm text-amber-800 dark:text-amber-200">
            <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5" />
            <p>Your comment has been submitted and is awaiting moderation. It will appear once approved.</p>
          </div>
        ) : (
          <div className="space-y-3">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <input value={name} onChange={e => setName(e.target.value)} className="input text-sm" placeholder="Name (optional)" />
              <input value={email} onChange={e => setEmail(e.target.value)} type="email" className="input text-sm" placeholder="Email (optional, not published)" />
            </div>
            <textarea value={content} onChange={e => setContent(e.target.value)}
              className="input text-sm resize-none" rows={4}
              placeholder="Share your thoughts on this article..." />
            {status === "error" && (
              <p className="text-sm text-red-600 flex items-center gap-1"><AlertCircle className="h-4 w-4" /> Something went wrong. Please try again.</p>
            )}
            <div className="flex items-center justify-between flex-wrap gap-2">
              <p className="text-xs text-muted-foreground">Comments are moderated. Be respectful.</p>
              <button onClick={handleSubmit} disabled={submitting || !content.trim()}
                className="btn-primary text-sm flex items-center gap-1.5">
                <Send className="h-4 w-4" /> {submitting ? "Posting..." : "Post Comment"}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Comments List */}
      {loading ? (
        <div className="space-y-4">
          {Array.from({length:3}).map((_,i) => <div key={i} className="h-20 bg-muted rounded-lg animate-pulse" />)}
        </div>
      ) : comments.length === 0 ? (
        <div className="text-center py-10 text-muted-foreground">
          <MessageCircle className="h-8 w-8 mx-auto mb-2 opacity-30" />
          <p className="text-sm">No comments yet. Be the first to share your thoughts!</p>
        </div>
      ) : (
        <div className="space-y-6">
          {comments.map(c => <CommentCard key={c.id} comment={c} postId={postId} />)}
        </div>
      )}
    </section>
  );
}
