// ─── Core Types ───────────────────────────────────────────────────────────────

export type Role = "SUPER_ADMIN" | "ADMIN" | "EDITOR" | "AUTHOR" | "VIEWER";
export type PostStatus = "DRAFT" | "REVIEW" | "SCHEDULED" | "PUBLISHED" | "ARCHIVED";
export type PostType = "ARTICLE" | "ANALYSIS" | "OPINION" | "PRESS_RELEASE" | "SPONSORED";
export type AIStatus = "PENDING" | "PROCESSING" | "COMPLETED" | "FAILED" | "HUMAN_REVIEW";
export type AdPlacement = "HEADER"|"SIDEBAR"|"BETWEEN_POSTS"|"ARTICLE_TOP"|"ARTICLE_BOTTOM"|"FOOTER"|"POPUP"|"IN_ARTICLE";
export type AdType = "IMAGE"|"ADSENSE"|"AFFILIATE"|"SPONSORED"|"CUSTOM_HTML";

// Helper: Prisma nullable fields come back as T | null, not T | undefined
type Nullable<T> = T | null;

export interface User {
  id: string;
  email: string;
  username: string;
  firstName: string;
  lastName: string;
  bio: Nullable<string>;
  avatar: Nullable<string>;
  role: Role;
  isActive: boolean;
  emailVerified: boolean;
  twitterHandle: Nullable<string>;
  linkedinUrl: Nullable<string>;
  websiteUrl: Nullable<string>;
  createdAt: Date;
  updatedAt: Date;
  _count?: { posts: number };
}

export interface Category {
  id: string;
  name: string;
  slug: string;
  description: Nullable<string>;
  image: Nullable<string>;
  color: Nullable<string>;
  icon: Nullable<string>;
  parentId: Nullable<string>;
  isActive: boolean;
  sortOrder: number;
  metaTitle: Nullable<string>;
  metaDesc: Nullable<string>;
  createdAt?: Date;
  updatedAt?: Date;
  _count?: { posts: number };
  children?: Category[];
}

export interface Tag {
  id: string;
  name: string;
  slug: string;
  description: Nullable<string>;
  color: Nullable<string>;
}

export interface Post {
  id: string;
  title: string;
  slug: string;
  excerpt: Nullable<string>;
  content: string;
  featuredImage: Nullable<string>;
  imageAlt: Nullable<string>;
  imageCaption: Nullable<string>;
  status: PostStatus;
  postType: PostType;
  authorId: string;
  categoryId: string;
  isFeatured: boolean;
  isTrending: boolean;
  isBreaking: boolean;
  allowComments: boolean;
  viewCount: number;
  shareCount: number;
  readingTime: number;
  publishedAt: Nullable<Date>;
  scheduledAt: Nullable<Date>;
  metaTitle: Nullable<string>;
  metaDesc: Nullable<string>;
  canonicalUrl: Nullable<string>;
  ogImage: Nullable<string>;
  noIndex: boolean;
  ampEnabled: boolean;
  isAiGenerated: boolean;
  aiQualityScore: Nullable<number>;
  sourceUrl: Nullable<string>;
  sourceName: Nullable<string>;
  createdAt: Date;
  updatedAt: Date;
  author: Pick<User, "id"|"firstName"|"lastName"|"username"|"avatar">;
  category: Pick<Category, "id"|"name"|"slug"|"color">;
  tags: { tag: Pick<Tag, "id"|"name"|"slug"> }[];
  _count?: { comments: number };
}

export interface Comment {
  id: string;
  postId: string;
  authorId: Nullable<string>;
  authorName: string;
  authorEmail: Nullable<string>;
  content: string;
  status: string;
  parentId: Nullable<string>;
  createdAt: Date;
  updatedAt: Date;
  replies?: Comment[];
}

export interface Advertisement {
  id: string;
  name: string;
  type: AdType;
  placement: AdPlacement;
  imageUrl: Nullable<string>;
  linkUrl: Nullable<string>;
  altText: Nullable<string>;
  htmlCode: Nullable<string>;    // raw HTML / affiliate embed
  adsenseSlot: Nullable<string>; // AdSense slot ID
  width: Nullable<number>;
  height: Nullable<number>;
  isActive: boolean;
  startDate: Nullable<Date>;
  endDate: Nullable<Date>;
  impressions: number;
  clicks: number;
  priority: number;
  createdAt: Date;
  updatedAt: Date;
}

export interface Newsletter {
  id: string;
  email: string;
  firstName: Nullable<string>;
  status: string;
  createdAt: Date;
}

export interface RssSource {
  id: string;
  name: string;
  url: string;
  category: Nullable<string>;
  isActive: boolean;
  fetchInterval: number;
  lastFetchedAt: Nullable<Date>;
  lastError: Nullable<string>;
  postsGenerated: number;
}

export interface AIContentLog {
  id: string;
  sourceUrl: Nullable<string>;
  sourceName: Nullable<string>;
  originalTitle: Nullable<string>;
  rewrittenTitle: Nullable<string>;
  status: AIStatus;
  qualityScore: Nullable<number>;
  errorMessage: Nullable<string>;
  postId: Nullable<string>;
  processingTimeMs: Nullable<number>;
  createdAt: Date;
}

export interface SeoSettings {
  id: string;
  siteName: string;
  tagline: Nullable<string>;
  siteUrl: string;
  defaultMetaTitle: Nullable<string>;
  defaultMetaDescription: Nullable<string>;
  defaultMetaKeywords: Nullable<string>;
  ogImage: Nullable<string>;
  twitterHandle: Nullable<string>;
  googleAnalyticsId: Nullable<string>;
  googleSearchConsoleId: Nullable<string>;
  robotsTxt: Nullable<string>;
  sitemapEnabled: boolean;
  schemaEnabled: boolean;
  ampEnabled: boolean;
  canonicalEnabled: boolean;
}

// ─── API Response wrappers ────────────────────────────────────────────────────
export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: string;
  message?: string;
  pagination?: { page: number; limit: number; total: number; totalPages: number };
}

export interface AuthTokens { accessToken: string; refreshToken: string }
export interface JwtPayload  { userId: string; email: string; role: string; iat?: number; exp?: number }

export interface DashboardStats {
  totalPosts: number;
  publishedPosts: number;
  draftPosts: number;
  totalUsers: number;
  totalViews: number;
  viewsToday: number;
  viewsThisMonth: number;
  newsletterSubscribers: number;
  aiLogsTotal: number;
  viewsByDay: { date: string; views: number }[];
  postsByCategory: { name: string; count: number }[];
  recentPosts: { id: string; title: string; views: number; status: string; createdAt: string }[];
}
