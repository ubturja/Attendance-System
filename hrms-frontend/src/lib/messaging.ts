import { AxiosHeaders } from 'axios';
import api from './api';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

export type ConversationKind = 'announcement' | 'general' | 'team' | 'direct';
export type SendPermission = 'admins_only' | 'all_members';
export type MessageReceipt = 'sent' | 'delivered' | 'seen';

export const MAX_MESSAGE_BYTES = 1073741824;

export interface ChatPermissions {
  can_send: boolean;
  can_join: boolean;
  can_manage_members: boolean;
  can_rename: boolean;
  can_change_send_permission: boolean;
  can_delete: boolean;
  can_restore: boolean;
  can_mention_everyone: boolean;
}

export interface ChatPerson {
  id: number;
  name: string;
  job_title?: string | null;
  team_id?: number | null;
  team_name?: string | null;
}

export interface ChatAttachment {
  id: number;
  original_name: string;
  mime_type: string | null;
  size_bytes: number;
}

export interface ChatMessage {
  id: number;
  conversation_id: number;
  kind: 'user' | 'system';
  body: string | null;
  placeholder: string | null;
  removed: boolean;
  edited_at: string | null;
  created_at: string | null;
  sender: { id: number; name: string; job_title: string } | null;
  reply_to: {
    id: number;
    body: string | null;
    placeholder: string | null;
    removed: boolean;
    sender_name: string | null;
  } | null;
  attachments: ChatAttachment[];
  mention_user_ids: number[];
  mention_everyone: boolean;
  receipt: MessageReceipt | null;
  seen_by: Array<{ id: number; name: string }>;
}

export interface ConversationSummary {
  id: number;
  kind: ConversationKind;
  name: string;
  send_permission: SendPermission;
  team_id: number | null;
  is_member: boolean;
  deleted_at: string | null;
  unread_count: number;
  has_unread_mention: boolean;
  members_count: number;
  other_user: { id: number; name: string; job_title: string } | null;
  last_message: {
    id: number;
    body: string | null;
    placeholder: string | null;
    kind: string;
    sender_id: number | null;
    sender_name: string | null;
    created_at: string | null;
  } | null;
  permissions: ChatPermissions;
  members?: ChatPerson[];
  typing?: Array<{ id: number; name: string }>;
}

export interface GroupOption {
  id: number;
  name: string;
  kind: ConversationKind;
  is_member: boolean;
}

export interface MessagePage {
  messages: ChatMessage[];
  has_more: boolean;
}

async function postForm<T>(url: string, form: FormData): Promise<T> {
  const response = await api.post<ApiSuccessResponse<T>>(url, form, {
    transformRequest: [
      (data: FormData, headers) => {
        if (headers instanceof AxiosHeaders) {
          headers.delete('Content-Type');
        }
        return data;
      },
    ],
  });

  return response.data.data;
}

async function patchForm<T>(url: string, form: FormData): Promise<T> {
  const response = await api.patch<ApiSuccessResponse<T>>(url, form, {
    transformRequest: [
      (data: FormData, headers) => {
        if (headers instanceof AxiosHeaders) {
          headers.delete('Content-Type');
        }
        return data;
      },
    ],
  });

  return response.data.data;
}

export async function fetchConversations(archived = false): Promise<ConversationSummary[]> {
  const response = await api.get<ApiSuccessResponse<ConversationSummary[]>>('/conversations', {
    params: archived ? { status: 'archived' } : undefined,
  });
  return response.data.data;
}

export async function fetchConversation(conversationId: number): Promise<ConversationSummary> {
  const response = await api.get<ApiSuccessResponse<ConversationSummary>>(
    `/conversations/${conversationId}`,
  );
  return response.data.data;
}

export async function createGroup(payload: {
  kind: 'announcement' | 'general';
  name: string;
  send_permission?: SendPermission;
  member_ids: number[];
}): Promise<ConversationSummary> {
  const response = await api.post<ApiSuccessResponse<ConversationSummary>>('/conversations', payload);
  return response.data.data;
}

export async function updateGroup(
  conversationId: number,
  payload: { name?: string; send_permission?: SendPermission },
): Promise<ConversationSummary> {
  const response = await api.patch<ApiSuccessResponse<ConversationSummary>>(
    `/conversations/${conversationId}`,
    payload,
  );
  return response.data.data;
}

export async function archiveGroup(conversationId: number): Promise<void> {
  await api.delete(`/conversations/${conversationId}`);
}

export async function restoreGroup(conversationId: number): Promise<ConversationSummary> {
  const response = await api.post<ApiSuccessResponse<ConversationSummary>>(
    `/conversations/${conversationId}/restore`,
  );
  return response.data.data;
}

export async function joinGroup(conversationId: number): Promise<ConversationSummary> {
  const response = await api.post<ApiSuccessResponse<ConversationSummary>>(
    `/conversations/${conversationId}/join`,
  );
  return response.data.data;
}

export async function addMembers(
  conversationId: number,
  userIds: number[],
): Promise<ConversationSummary> {
  const response = await api.post<ApiSuccessResponse<ConversationSummary>>(
    `/conversations/${conversationId}/members`,
    { user_ids: userIds },
  );
  return response.data.data;
}

export async function removeMember(
  conversationId: number,
  userId: number,
): Promise<ConversationSummary> {
  const response = await api.delete<ApiSuccessResponse<ConversationSummary>>(
    `/conversations/${conversationId}/members/${userId}`,
  );
  return response.data.data;
}

export async function openDirect(userId: number): Promise<ConversationSummary> {
  const response = await api.post<ApiSuccessResponse<ConversationSummary>>('/conversations/direct', {
    user_id: userId,
  });
  return response.data.data;
}

export async function fetchMessages(
  conversationId: number,
  options: { beforeId?: number; search?: string } = {},
): Promise<MessagePage> {
  const response = await api.get<ApiSuccessResponse<MessagePage>>(
    `/conversations/${conversationId}/messages`,
    {
      params: {
        before_id: options.beforeId,
        q: options.search !== undefined && options.search.trim() !== '' ? options.search.trim() : undefined,
      },
    },
  );
  return response.data.data;
}

export interface OutgoingMessage {
  body: string;
  files: File[];
  replyToMessageId?: number;
  mentionUserIds: number[];
  mentionEveryone: boolean;
}

function appendOutgoing(form: FormData, message: OutgoingMessage): void {
  form.append('body', message.body);
  if (message.replyToMessageId !== undefined) {
    form.append('reply_to_message_id', String(message.replyToMessageId));
  }
  form.append('mention_everyone', message.mentionEveryone ? '1' : '0');
  for (const userId of message.mentionUserIds) {
    form.append('mention_user_ids[]', String(userId));
  }
  for (const file of message.files) {
    form.append('files[]', file);
  }
}

export async function sendMessage(
  conversationId: number,
  message: OutgoingMessage,
): Promise<ChatMessage> {
  const form = new FormData();
  appendOutgoing(form, message);
  return postForm<ChatMessage>(`/conversations/${conversationId}/messages`, form);
}

export async function editMessage(
  messageId: number,
  message: OutgoingMessage,
  keepAttachmentIds: number[],
): Promise<ChatMessage> {
  const form = new FormData();
  form.append('body', message.body);
  form.append('mention_everyone', message.mentionEveryone ? '1' : '0');
  form.append('keep_attachment_ids', JSON.stringify(keepAttachmentIds));
  for (const userId of message.mentionUserIds) {
    form.append('mention_user_ids[]', String(userId));
  }
  for (const file of message.files) {
    form.append('files[]', file);
  }
  return patchForm<ChatMessage>(`/messages/${messageId}`, form);
}

export async function deleteMessage(messageId: number): Promise<ChatMessage> {
  const response = await api.delete<ApiSuccessResponse<ChatMessage>>(`/messages/${messageId}`);
  return response.data.data;
}

export async function revealMessage(messageId: number): Promise<ChatMessage> {
  const response = await api.get<ApiSuccessResponse<ChatMessage>>(`/messages/${messageId}/reveal`);
  return response.data.data;
}

export async function markRead(conversationId: number, messageId: number): Promise<void> {
  await api.post(`/conversations/${conversationId}/read`, { message_id: messageId });
}

export async function markDelivered(conversationId: number, messageId: number): Promise<void> {
  await api.post(`/conversations/${conversationId}/delivered`, { message_id: messageId });
}

export async function postTyping(conversationId: number): Promise<void> {
  await api.post(`/conversations/${conversationId}/typing`);
}

export async function fetchDirectory(conversationId?: number): Promise<ChatPerson[]> {
  const response = await api.get<ApiSuccessResponse<ChatPerson[]>>('/messaging/directory', {
    params: conversationId !== undefined ? { conversation_id: conversationId } : undefined,
  });
  return response.data.data;
}

export async function fetchGroupOptions(userId: number, teamId: number | null): Promise<GroupOption[]> {
  const response = await api.get<ApiSuccessResponse<GroupOption[]>>('/messaging/group-options', {
    params: {
      user_id: userId,
      team_id: teamId ?? undefined,
    },
  });
  return response.data.data;
}

export async function fetchUnreadCount(): Promise<number> {
  const response = await api.get<ApiSuccessResponse<{ unread_count: number }>>(
    '/messaging/unread-count',
  );
  return response.data.data.unread_count;
}

export async function downloadAttachment(attachmentId: number, reveal = false): Promise<Blob> {
  const response = await api.get<Blob>(`/messaging/attachments/${attachmentId}`, {
    params: reveal ? { reveal: 1 } : undefined,
    responseType: 'blob',
  });
  return response.data;
}

export function totalFileBytes(files: File[]): number {
  return files.reduce((sum, file) => sum + file.size, 0);
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  if (bytes < 1024 * 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}
