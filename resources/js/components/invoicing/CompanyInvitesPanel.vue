<template>
  <div class="mt-4 border-t border-gray-100 pt-4" data-testid="company-invites-panel">
    <h3 class="text-sm font-semibold text-gray-900">{{ t('invoicing.company_invite_title') }}</h3>
    <p class="mt-1 text-xs text-gray-500 max-w-2xl">{{ t('invoicing.company_invite_intro') }}</p>

    <div class="mt-3 flex flex-wrap items-end gap-2">
      <label class="flex flex-col text-xs text-gray-600">
        <span class="mb-1">{{ t('invoicing.company_invite_role') }}</span>
        <select v-model="role" class="invoicing-input text-sm">
          <option value="accountant">{{ t('invoicing.company_invite_role_accountant') }}</option>
          <option value="member">{{ t('invoicing.company_invite_role_member') }}</option>
        </select>
      </label>
      <label class="flex flex-1 min-w-[12rem] flex-col text-xs text-gray-600">
        <span class="mb-1">{{ t('invoicing.company_invite_email') }}</span>
        <input v-model.trim="email" type="email" class="invoicing-input text-sm" :placeholder="t('invoicing.company_invite_email_ph')" @keyup.enter="lookup" />
      </label>
      <button type="button" class="invoicing-btn-secondary text-sm" :disabled="busy || !email" @click="lookup">
        {{ t('invoicing.company_invite_lookup') }}
      </button>
    </div>

    <div v-if="recipient" class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm space-y-2">
      <template v-if="recipient.found && recipient.has_recovery && !recipient.is_self">
        <p v-if="recipient.already_member" class="text-amber-700">{{ t('invoicing.company_invite_already_member') }}</p>
        <div>
          <p class="text-xs text-gray-600">{{ t('invoicing.company_invite_fingerprint_hint') }}</p>
          <p class="mt-1 font-mono text-xs tracking-wide text-gray-900">{{ fingerprint }}</p>
        </div>
        <button type="button" class="invoicing-btn-primary text-sm" :disabled="busy" @click="sendSealed">
          {{ t('invoicing.company_invite_send_sealed') }}
        </button>
      </template>
      <template v-else-if="recipient.is_self">
        <p class="text-amber-700">{{ t('invoicing.company_invite_self') }}</p>
      </template>
      <template v-else>
        <p class="text-gray-700">{{ t('invoicing.company_invite_no_recovery') }}</p>
        <button type="button" class="invoicing-btn-secondary text-sm" :disabled="busy" @click="createLink">
          {{ t('invoicing.company_invite_create_link') }}
        </button>
      </template>
    </div>

    <div v-if="generatedLink" class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm space-y-2">
      <p class="font-medium text-emerald-900">{{ t('invoicing.company_invite_link_ready') }}</p>
      <p v-if="linkHasSecret" class="text-xs text-amber-700">{{ t('invoicing.company_invite_link_secret_warning') }}</p>
      <div class="flex items-center gap-2">
        <input :value="generatedLink" readonly class="invoicing-input flex-1 font-mono text-xs" @focus="selectAll" />
        <button type="button" class="invoicing-btn-secondary text-xs" @click="copyLink">{{ copied ? t('common.copied') : t('common.copy') }}</button>
      </div>
    </div>

    <p v-if="errorMsg" class="mt-2 text-sm text-red-600">{{ errorMsg }}</p>

    <div v-if="invites.length" class="mt-4">
      <p class="text-xs font-medium text-gray-600">{{ t('invoicing.company_invite_pending') }}</p>
      <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-100">
        <li v-for="inv in invites" :key="inv.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
          <span class="min-w-0">
            <span class="font-medium text-gray-900">{{ inv.invited_email || t(`invoicing.company_invite_role_${inv.role}`) }}</span>
            <span class="ml-2 text-xs text-gray-500">{{ t(`invoicing.company_invite_role_${inv.role}`) }} · {{ inv.mode === 'link' ? t('invoicing.company_invite_mode_link') : t('invoicing.company_invite_mode_sealed') }}</span>
          </span>
          <button type="button" class="text-xs text-red-600 hover:underline" :disabled="busy" @click="revoke(inv.id)">
            {{ t('invoicing.company_invite_revoke') }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { invoicingApi, type CompanyInviteSummary, type InviteRecipientLookup } from '@/services/api';
import { useInvoicingEvolu } from '@/evolu/client';
import { inviteFingerprint } from '@/services/companyInviteSeal';
import { readOwnShareSecret, sealShareForRecipient } from '@/evolu/companyInviteCreate';

/**
 * Owner-only invite surface for a shared company (docs/COMPANY_SHARING.md, C4).
 * Seals the company's SharedOwner secret to the invitee's recovery key (or,
 * as a fallback, hands back a link whose fragment carries the secret). The
 * plaintext secret never reaches the server.
 */
const props = defineProps<{ companyId: string }>();
const { t } = useI18n();
const evolu = useInvoicingEvolu();

const role = ref<'accountant' | 'member'>('accountant');
const email = ref('');
const recipient = ref<InviteRecipientLookup | null>(null);
const invites = ref<CompanyInviteSummary[]>([]);
const busy = ref(false);
const errorMsg = ref('');
const generatedLink = ref('');
const linkHasSecret = ref(false);
const copied = ref(false);

const fingerprint = computed(() => (recipient.value?.public_key ? inviteFingerprint(recipient.value.public_key) : null));

function acceptUrl(token: string, secret?: string): string {
  const base = `${window.location.origin}/invoicing/invite/${token}`;
  return secret ? `${base}#s=${encodeURIComponent(secret)}` : base;
}

async function refresh(): Promise<void> {
  try {
    invites.value = await invoicingApi.companies.listInvites(props.companyId);
  } catch {
    // non-fatal
  }
}

async function lookup(): Promise<void> {
  errorMsg.value = '';
  generatedLink.value = '';
  recipient.value = null;
  if (!email.value) return;
  busy.value = true;
  try {
    recipient.value = await invoicingApi.companies.lookupInviteRecipient(props.companyId, email.value);
    if (!recipient.value.found) errorMsg.value = t('invoicing.company_invite_not_found');
  } catch {
    errorMsg.value = t('common.error');
  } finally {
    busy.value = false;
  }
}

async function sendSealed(): Promise<void> {
  if (!recipient.value?.public_key) return;
  errorMsg.value = '';
  busy.value = true;
  try {
    const sealed = await sealShareForRecipient(evolu, props.companyId, recipient.value.public_key);
    if (!sealed.ok) {
      errorMsg.value = t(`invoicing.company_invite_error_${sealed.error}`);
      return;
    }
    const { token } = await invoicingApi.companies.createInvite(props.companyId, {
      role: role.value,
      mode: 'sealed',
      invited_email: email.value,
      invitee_public_key: recipient.value.public_key,
      sealed_secret: sealed.sealed,
    });
    generatedLink.value = acceptUrl(token);
    linkHasSecret.value = false;
    recipient.value = null;
    await refresh();
  } catch (error) {
    errorMsg.value = messageFrom(error);
  } finally {
    busy.value = false;
  }
}

async function createLink(): Promise<void> {
  errorMsg.value = '';
  busy.value = true;
  try {
    const secret = await readOwnShareSecret(evolu, props.companyId);
    if (!secret.ok) {
      errorMsg.value = t('invoicing.company_invite_error_not_shared');
      return;
    }
    const { token } = await invoicingApi.companies.createInvite(props.companyId, {
      role: role.value,
      mode: 'link',
      invited_email: email.value || null,
    });
    generatedLink.value = acceptUrl(token, secret.secretEncoded);
    linkHasSecret.value = true;
    recipient.value = null;
    await refresh();
  } catch (error) {
    errorMsg.value = messageFrom(error);
  } finally {
    busy.value = false;
  }
}

async function revoke(id: string): Promise<void> {
  errorMsg.value = '';
  busy.value = true;
  try {
    await invoicingApi.companies.revokeInvite(props.companyId, id);
    await refresh();
  } catch (error) {
    errorMsg.value = messageFrom(error);
  } finally {
    busy.value = false;
  }
}

function messageFrom(error: unknown): string {
  const msg = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
  return msg ? t(`invoicing.company_invite_error_${msg}`) : t('common.error');
}

function selectAll(event: FocusEvent): void {
  (event.target as HTMLInputElement).select();
}

async function copyLink(): Promise<void> {
  try {
    await navigator.clipboard.writeText(generatedLink.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
  } catch {
    // clipboard blocked - the field is selectable
  }
}

onMounted(refresh);
</script>
