import { ref } from "vue";
import api from "../services/api";

/**
 * Unread counters from /messages/count, shared by the header badge and the
 * security banner so the app makes one request per refresh, not one per
 * component. Only the newest response is applied (stale replies from a
 * previous user or an earlier call are dropped).
 */
const unread = ref(0);
const securityUnread = ref(0);
let generation = 0;

export function useMessageCounts() {
  async function load(userId: number | null | undefined): Promise<void> {
    const mine = ++generation;
    if (!userId) {
      unread.value = 0;
      securityUnread.value = 0;
      return;
    }
    try {
      const response = await api.get("/messages/count");
      if (mine !== generation) return;
      unread.value = Number(response.data?.data?.unread ?? 0);
      securityUnread.value = Number(response.data?.data?.security_unread ?? 0);
    } catch (error: unknown) {
      if (mine !== generation) return;
      unread.value = 0;
      securityUnread.value = 0;
      const status = (error as { response?: { status?: number } })?.response?.status;
      if (status !== 401 && status !== 403) {
        console.error("Failed to load message count:", error);
      }
    }
  }

  return { unread, securityUnread, load };
}
