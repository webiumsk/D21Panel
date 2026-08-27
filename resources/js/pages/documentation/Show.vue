<template>
  <div class="min-h-screen bg-gray-900">
    <PublicHeader />

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
      <div class="flex flex-col lg:flex-row gap-10">
        <!-- Sidebar: Categories & Articles -->
        <aside class="lg:w-72 flex-shrink-0 border-r border-gray-700">
          <div class="lg:sticky lg:top-24 p-5 overflow-hidden">
            <h2
              class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4"
            >
              {{ t("documentation.categories") }}
            </h2>
            <div v-if="sidebarLoading" class="flex justify-center py-8">
              <svg
                class="animate-spin h-6 w-6 text-indigo-500"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
            </div>
            <nav v-else class="space-y-6">
              <div
                v-for="group in sidebarNav"
                :key="group.category?.id ?? 'uncategorized'"
                class="space-y-2"
              >
                <div
                  class="text-xs font-semibold text-gray-500 uppercase tracking-wider"
                >
                  {{ group.category?.name ?? t("documentation.uncategorized") }}
                </div>
                <ul class="space-y-1">
                  <li v-for="a in group.articles" :key="a.slug">
                    <router-link
                      :to="`/documentation/${a.slug}`"
                      :class="[
                        'block rounded-lg px-3 py-2 text-sm transition-colors',
                        route.params.slug === a.slug
                          ? 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/30'
                          : 'text-gray-400 hover:text-white hover:bg-gray-700/50',
                      ]"
                    >
                      {{ a.title }}
                    </router-link>
                  </li>
                </ul>
              </div>
            </nav>
          </div>
        </aside>

        <!-- Main Content -->
        <main class="min-w-0 flex-1">
          <!-- Loading State -->
          <div v-if="loading" class="flex justify-center items-center py-24">
            <svg
              class="animate-spin h-10 w-10 text-indigo-500"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              ></circle>
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              ></path>
            </svg>
          </div>

          <!-- Article Content -->
          <article v-else-if="article" class="p-8">
            <!-- Back + Edit (Admin/Support) -->
            <div class="flex items-center justify-between gap-4 mb-6">
              <router-link
                to="/documentation"
                class="inline-flex items-center text-sm text-gray-400 hover:text-indigo-400 transition-colors"
              >
                <svg
                  class="w-4 h-4 mr-2"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M10 19l-7-7m0 0l7-7m-7 7h18"
                  />
                </svg>
                {{ t("documentation.back_to_documentation") }}
              </router-link>
            </div>

            <!-- Category Badge -->
            <div v-if="article.category" class="mb-4">
              <span
                class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/20"
              >
                {{ article.category.name }}
              </span>
            </div>

            <!-- Title -->
            <h1 class="text-4xl font-bold text-white mb-4">
              {{ article.title }}
            </h1>

            <!-- Meta -->
            <div
              class="flex items-center text-sm text-gray-400 mb-8 pb-8 border-b border-gray-700"
            >
              <span v-if="article.created_at">{{ formatDate(article.created_at) }}</span>
              <span
                v-if="article.updated_at && article.updated_at !== article.created_at"
                class="ml-4"
              >
                {{ t("documentation.updated") }}:
                {{ formatDate(article.updated_at) }}
              </span>
            </div>

            <!-- Content (sanitized HTML from rich editor or legacy plain text) -->
            <div
              class="prose prose-invert prose-lg max-w-none doc-content"
              v-html="sanitizedContent(article.content ?? '')"
            ></div>
          </article>

          <!-- Not Found -->
          <div v-else class="text-center py-20">
            <h2 class="text-2xl font-bold text-white mb-4">
              {{ t("documentation.article_not_found") }}
            </h2>
            <router-link
              to="/documentation"
              class="text-indigo-400 hover:text-indigo-300"
            >
              {{ t("documentation.back_to_documentation") }}
            </router-link>
          </div>
        </main>
      </div>
    </div>

    <AppFooter />
  </div>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, computed, onMounted, watch } from "vue";
import { useRoute } from "vue-router";
import { useI18n } from "vue-i18n";
import DOMPurify from "dompurify";
import { updatePageMeta } from "../../composables/usePageMeta";
import { documentationApi } from "../../services/api";
import PublicHeader from "../../components/layout/PublicHeader.vue";
import AppFooter from "../../components/layout/AppFooter.vue";

const route = useRoute();
const { t, locale } = useI18n();
interface DocArticle {
  id: string;
  slug: string;
  title: string;
  content?: string;
  created_at?: string;
  updated_at?: string;
  category_id?: string | number | null;
  category?: { id: string | number; name: string } | null;
  [key: string]: unknown;
}

const loading = ref(false);
const sidebarLoading = ref(false);
const article = ref<DocArticle | null>(null);
const sidebarArticles = ref<DocArticle[]>([]);
const sidebarCategories = ref<{ id: string | number; name: string }[]>([]);

interface SidebarGroup {
  category: { id: string; name: string } | null;
  articles: { slug: string; title: string }[];
}

const sidebarNav = computed<SidebarGroup[]>(() => {
  const categories = sidebarCategories.value;
  const articles = sidebarArticles.value;
  if (!articles.length) return [];
  const byCategory = new Map<string, { slug: string; title: string }[]>();
  articles.forEach((a) => {
    const rawId = a.category_id ?? a.category?.id;
    const key = rawId != null ? String(rawId) : "none";
    if (!byCategory.has(key)) byCategory.set(key, []);
    byCategory.get(key)!.push({ slug: a.slug, title: a.title });
  });
  const result: SidebarGroup[] = [];
  categories.forEach((cat: { id: string | number; name: string }) => {
    const idStr = String(cat.id);
    const list = byCategory.get(idStr);
    if (list?.length)
      result.push({ category: { id: idStr, name: cat.name }, articles: list });
  });
  const uncategorized = byCategory.get("none");
  if (uncategorized?.length)
    result.push({ category: null, articles: uncategorized });
  return result;
});

const loadSidebar = async () => {
  sidebarLoading.value = true;
  try {
    const response = await documentationApi.index({ locale: locale.value });
    sidebarArticles.value = response.data.data || [];
    sidebarCategories.value = response.data.categories || [];
  } catch (error) {
    console.error("Failed to load sidebar:", error);
    sidebarArticles.value = [];
    sidebarCategories.value = [];
  } finally {
    sidebarLoading.value = false;
  }
};

const loadArticle = async () => {
  loading.value = true;
  try {
    const slug = route.params.slug as string;
    const response = await documentationApi.show(slug, {
      locale: locale.value,
    });
    article.value = response.data.data;
  } catch (rawError) {
    const error = asApiError(rawError);
    console.error("Failed to load article:", error);
    if (error.response?.status === 404) {
      article.value = null;
    }
  } finally {
    loading.value = false;
  }
};

const formatDate = (dateString: string) => {
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const ALLOWED_TAGS = [
  "h1",
  "h2",
  "h3",
  "h4",
  "h5",
  "h6",
  "p",
  "br",
  "strong",
  "em",
  "u",
  "s",
  "a",
  "ul",
  "ol",
  "li",
  "blockquote",
  "pre",
  "code",
  "table",
  "thead",
  "tbody",
  "tr",
  "th",
  "td",
  "img",
  "iframe",
  "span",
  "div",
];
const ALLOWED_ATTR = [
  "href",
  "src",
  "alt",
  "title",
  "class",
  "width",
  "height",
  "allow",
  "allowfullscreen",
  "frameborder",
];

function sanitizedContent(content: string): string {
  if (!content) return "";
  // Legacy: plain text (no HTML) - apply simple markdown-like formatting
  if (!content.includes("<")) {
    let formatted = content.replace(/\n\n/g, "</p><p>");
    formatted = formatted.replace(/\n/g, "<br>");
    formatted = formatted.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
    formatted = formatted.replace(/\*(.*?)\*/g, "<em>$1</em>");
    formatted = formatted.replace(/^### (.*$)/gim, "<h3>$1</h3>");
    formatted = formatted.replace(/^## (.*$)/gim, "<h2>$1</h2>");
    formatted = formatted.replace(/^# (.*$)/gim, "<h1>$1</h1>");
    content = `<p>${formatted}</p>`;
  }
  // Restrict iframe src to YouTube only
  DOMPurify.addHook("uponSanitizeAttribute", (node, data) => {
    if (data.attrName === "src" && node.tagName === "IFRAME") {
      const url = data.attrValue || "";
      const allowed =
        url.startsWith("https://www.youtube.com/") ||
        url.startsWith("https://www.youtube-nocookie.com/");
      if (!allowed) data.attrValue = "";
    }
  });
  const result = DOMPurify.sanitize(content, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ADD_ATTR: ["allowfullscreen"],
    ADD_TAGS: ["iframe"],
  });
  DOMPurify.removeHook("uponSanitizeAttribute");
  return result;
}

onMounted(() => {
  loadSidebar();
  loadArticle();
});

watch(
  () => route.params.slug,
  (newSlug, oldSlug) => {
    if (newSlug && newSlug !== oldSlug) {
      loadArticle();
    }
  },
);

watch(locale, () => {
  loadSidebar();
  if (route.params.slug) {
    loadArticle();
  }
});

watch(
  article,
  (a: { title: string; meta_description?: string } | null) => {
    if (a) {
      updatePageMeta(route, {
        title: a.title,
        description: a.meta_description || a.title,
      });
    }
  },
  { immediate: true },
);
</script>

<style scoped>
.doc-content {
  color: #e5e7eb;
}

.doc-content :deep(h1),
.doc-content :deep(h2),
.doc-content :deep(h3),
.doc-content :deep(h4),
.doc-content :deep(h5),
.doc-content :deep(h6) {
  color: #ffffff;
  font-weight: 700;
  margin-top: 2em;
  margin-bottom: 1em;
}

.doc-content :deep(h1) {
  font-size: 2.25em;
}
.doc-content :deep(h2) {
  font-size: 1.875em;
}
.doc-content :deep(h3) {
  font-size: 1.5em;
}
.doc-content :deep(h4) {
  font-size: 1.25em;
}
.doc-content :deep(h5) {
  font-size: 1.125em;
}
.doc-content :deep(h6) {
  font-size: 1em;
}

.doc-content :deep(p) {
  margin-bottom: 1.25em;
  line-height: 1.75;
}

.doc-content :deep(strong) {
  color: #ffffff;
  font-weight: 600;
}

.doc-content :deep(em) {
  font-style: italic;
}

.doc-content :deep(a) {
  color: #818cf8;
  text-decoration: underline;
}

.doc-content :deep(a:hover) {
  color: #a5b4fc;
}

.doc-content :deep(table) {
  border-collapse: collapse;
  margin: 1.5em 0;
  width: 100%;
}

.doc-content :deep(th),
.doc-content :deep(td) {
  border: 1px solid #4b5563;
  padding: 0.5rem 0.75rem;
  text-align: left;
}

.doc-content :deep(th) {
  background: #374151;
  color: #fff;
  font-weight: 600;
}

.doc-content :deep(pre) {
  background: #1f2937;
  border-radius: 0.5rem;
  padding: 1rem;
  overflow-x: auto;
  margin: 1.5em 0;
}

.doc-content :deep(pre code) {
  font-size: 0.875rem;
  color: #e5e7eb;
}

.doc-content :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 0.375rem;
}

.doc-content :deep(iframe) {
  max-width: 100%;
  border-radius: 0.375rem;
  aspect-ratio: 16 / 9;
}
</style>
