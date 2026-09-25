import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import {
  Archive,
  Check,
  CheckCheck,
  Loader2,
  Paperclip,
  Plus,
  Search,
  SendHorizontal,
  X,
} from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { useCurrentProfile } from '../../hooks/useCurrentProfile';
import { getApiErrorMessage } from '../../lib/errors';
import {
  addMembers,
  archiveGroup,
  createGroup,
  deleteMessage,
  downloadAttachment,
  editMessage,
  fetchConversation,
  fetchConversations,
  fetchDirectory,
  fetchMessages,
  formatBytes,
  joinGroup,
  MAX_MESSAGE_BYTES,
  markDelivered,
  markRead,
  openDirect,
  postTyping,
  removeMember,
  restoreGroup,
  revealMessage,
  sendMessage,
  totalFileBytes,
  updateGroup,
  type ChatAttachment,
  type ChatMessage,
  type ChatPerson,
  type ConversationKind,
  type ConversationSummary,
  type SendPermission,
} from '../../lib/messaging';
import { cn } from '../../lib/utils';

const conversationKey = (archived: boolean) => ['messaging', 'conversations', archived] as const;

function kindLabel(kind: ConversationKind): string {
  if (kind === 'announcement') return 'Announcement';
  if (kind === 'general') return 'General';
  if (kind === 'team') return 'Team';
  return 'Direct';
}

function messagePreview(message: ConversationSummary['last_message']): string {
  if (message === null) return 'No messages yet';
  if (message.placeholder) return message.placeholder;
  if (message.body && message.body.trim() !== '') return message.body;
  return 'Attachment';
}

function formatClock(value: string | null): string {
  if (value === null) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function dedupeMessages(messages: ChatMessage[]): ChatMessage[] {
  const byId = new Map<number, ChatMessage>();
  for (const message of messages) {
    byId.set(message.id, message);
  }
  return Array.from(byId.values()).sort((a, b) => a.id - b.id);
}

function AttachmentView({
  attachment,
  reveal,
}: {
  attachment: ChatAttachment;
  reveal: boolean;
}) {
  const [url, setUrl] = useState<string | null>(null);
  const isImage =
    attachment.mime_type !== null &&
    attachment.mime_type.startsWith('image/') &&
    attachment.mime_type !== 'image/svg+xml';

  useEffect(() => {
    if (!isImage) return undefined;
    let objectUrl = '';
    let cancelled = false;
    void downloadAttachment(attachment.id, reveal).then((blob) => {
      objectUrl = URL.createObjectURL(blob);
      if (!cancelled) setUrl(objectUrl);
    });
    return () => {
      cancelled = true;
      if (objectUrl !== '') URL.revokeObjectURL(objectUrl);
    };
  }, [attachment.id, isImage, reveal]);

  async function download() {
    const blob = await downloadAttachment(attachment.id, reveal);
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = attachment.original_name;
    link.click();
    URL.revokeObjectURL(objectUrl);
  }

  return (
    <div className="mt-2">
      {isImage && url !== null ? (
        <button type="button" onClick={() => void download()} className="block">
          <img src={url} alt={attachment.original_name} className="max-h-48 rounded-md" />
        </button>
      ) : null}
      <button
        type="button"
        onClick={() => void download()}
        className="mt-1 block max-w-full truncate text-left text-xs font-medium text-brand underline"
      >
        {attachment.original_name}
        <span className="ml-1 font-normal text-slate-500">{formatBytes(attachment.size_bytes)}</span>
      </button>
    </div>
  );
}

export default function Messages() {
  const queryClient = useQueryClient();
  const profile = useCurrentProfile().data;
  const isAdmin = profile?.job_title === 'Admin';
  const myId = profile?.id ?? null;

  const [archived, setArchived] = useState(false);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [listSearch, setListSearch] = useState('');
  const [threadSearch, setThreadSearch] = useState('');
  const [draft, setDraft] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null);
  const [editing, setEditing] = useState<ChatMessage | null>(null);
  const [keptAttachmentIds, setKeptAttachmentIds] = useState<number[]>([]);
  const [mentionIds, setMentionIds] = useState<number[]>([]);
  const [mentionEveryone, setMentionEveryone] = useState(false);
  const [older, setOlder] = useState<ChatMessage[]>([]);
  const [hasOlder, setHasOlder] = useState(false);
  const [error, setError] = useState<string | undefined>();
  const [showCreate, setShowCreate] = useState(false);
  const [showDirect, setShowDirect] = useState(false);
  const [showInfo, setShowInfo] = useState(false);
  const [revealed, setRevealed] = useState<Record<number, ChatMessage>>({});
  const [seenFor, setSeenFor] = useState<number | null>(null);
  const scrollerRef = useRef<HTMLDivElement>(null);
  const stickRef = useRef(true);
  const markedRef = useRef(0);
  const deliveredRef = useRef<Set<number>>(new Set());
  const fileInputRef = useRef<HTMLInputElement>(null);

  const conversationsQuery = useQuery({
    queryKey: conversationKey(archived),
    queryFn: () => fetchConversations(archived),
    refetchInterval: archived ? false : 1000,
  });

  const selected = useMemo(
    () => (conversationsQuery.data ?? []).find((item) => item.id === selectedId) ?? null,
    [conversationsQuery.data, selectedId],
  );

  const detailQuery = useQuery({
    queryKey: ['messaging', 'conversation', selectedId],
    queryFn: () => fetchConversation(selectedId as number),
    enabled: selectedId !== null,
    refetchInterval: 1000,
  });

  const conversation = detailQuery.data ?? selected;
  const isMember = conversation?.is_member === true && conversation.deleted_at === null;
  const search = threadSearch.trim();

  const messagesQuery = useQuery({
    queryKey: ['messaging', 'messages', selectedId, search],
    queryFn: () => fetchMessages(selectedId as number, { search: search || undefined }),
    enabled: selectedId !== null && isMember,
    refetchInterval: search === '' ? 1000 : false,
  });

  useEffect(() => {
    setOlder([]);
    setReplyTo(null);
    setEditing(null);
    setDraft('');
    setFiles([]);
    setMentionIds([]);
    setMentionEveryone(false);
    setRevealed({});
    setSeenFor(null);
    setThreadSearch('');
    markedRef.current = 0;
    stickRef.current = true;
  }, [selectedId]);

  useEffect(() => {
    if (older.length === 0 && messagesQuery.data) {
      setHasOlder(messagesQuery.data.has_more);
    }
  }, [messagesQuery.data, older.length, selectedId]);

  const messages = useMemo(
    () => dedupeMessages([...older, ...(messagesQuery.data?.messages ?? [])]),
    [older, messagesQuery.data],
  );

  useEffect(() => {
    if (!stickRef.current) return;
    const node = scrollerRef.current;
    if (node) node.scrollTop = node.scrollHeight;
  }, [messages, conversation?.typing]);

  useEffect(() => {
    if (myId === null || archived) return;
    for (const item of conversationsQuery.data ?? []) {
      const last = item.last_message;
      if (!item.is_member || last === null || last.sender_id === myId || last.kind === 'system') {
        continue;
      }
      if (deliveredRef.current.has(last.id)) continue;
      deliveredRef.current.add(last.id);
      void markDelivered(item.id, last.id);
    }
  }, [conversationsQuery.data, myId, archived]);

  useEffect(() => {
    if (!isMember || selectedId === null || messages.length === 0 || search !== '') return;
    const latest = messages[messages.length - 1];
    if (latest === undefined || latest.id <= markedRef.current) return;
    markedRef.current = latest.id;
    void markRead(selectedId, latest.id);
    void markDelivered(selectedId, latest.id);
  }, [messages, isMember, selectedId, search]);

  useEffect(() => {
    if (!isMember || selectedId === null || conversation?.permissions.can_send !== true) return;
    if (draft.trim() === '' && files.length === 0) return;
    void postTyping(selectedId);
    const timer = window.setInterval(() => {
      void postTyping(selectedId);
    }, 2000);
    return () => window.clearInterval(timer);
  }, [draft, files.length, isMember, selectedId, conversation?.permissions.can_send]);

  const visibleConversations = (conversationsQuery.data ?? []).filter((item) => {
    const query = listSearch.trim().toLowerCase();
    if (query === '') return true;
    return item.name.toLowerCase().includes(query);
  });

  const mentionQuery = useMemo(() => {
    const match = draft.match(/(^|\s)@([^\n@]*)$/);
    return match?.[2] ?? null;
  }, [draft]);

  const mentionChoices = useMemo(() => {
    if (mentionQuery === null || conversation?.members === undefined) return [];
    const query = mentionQuery.toLowerCase();
    const people = conversation.members.filter((member) => member.name.toLowerCase().includes(query));
    const everyone =
      conversation.permissions.can_mention_everyone && 'everyone'.includes(query)
        ? [{ id: 0, name: 'everyone' }]
        : [];
    return [...everyone, ...people].slice(0, 8);
  }, [mentionQuery, conversation]);

  function invalidateMessaging() {
    void queryClient.invalidateQueries({ queryKey: ['messaging'] });
  }

  function reportError(caught: unknown, fallback: string) {
    setError(getApiErrorMessage(caught, fallback));
  }

  const sendMutation = useMutation({
    mutationFn: async () => {
      if (selectedId === null) return;
      const payload = {
        body: draft,
        files,
        replyToMessageId: replyTo?.id,
        mentionUserIds: mentionIds.filter((id) => {
          const person = conversation?.members?.find((member) => member.id === id);
          return person !== undefined && draft.includes(`@${person.name}`);
        }),
        mentionEveryone: mentionEveryone && /(?:^|\s)@everyone\b/i.test(draft),
      };
      if (editing !== null) {
        return editMessage(editing.id, payload, keptAttachmentIds);
      }
      return sendMessage(selectedId, payload);
    },
    onSuccess: () => {
      setDraft('');
      setFiles([]);
      setReplyTo(null);
      setEditing(null);
      setKeptAttachmentIds([]);
      setMentionIds([]);
      setMentionEveryone(false);
      setError(undefined);
      stickRef.current = true;
      invalidateMessaging();
    },
    onError: (caught) => reportError(caught, 'The message could not be sent.'),
  });

  function chooseFiles(list: FileList | null) {
    if (list === null) return;
    const next = [...files, ...Array.from(list)];
    if (totalFileBytes(next) > MAX_MESSAGE_BYTES) {
      setError('One message cannot be larger than 1GB.');
      return;
    }
    setFiles(next);
    setError(undefined);
  }

  function insertMention(person: { id: number; name: string }) {
    const nextDraft = draft.replace(/(^|\s)@([^\n@]*)$/, `$1@${person.name} `);
    setDraft(nextDraft);
    if (person.id === 0) {
      setMentionEveryone(true);
      return;
    }
    setMentionIds((current) => (current.includes(person.id) ? current : [...current, person.id]));
  }

  function startEdit(message: ChatMessage) {
    setEditing(message);
    setReplyTo(null);
    setDraft(message.body ?? '');
    setFiles([]);
    setKeptAttachmentIds(message.attachments.map((file) => file.id));
    setMentionIds(message.mention_user_ids);
    setMentionEveryone(message.mention_everyone);
  }

  async function loadOlder() {
    if (selectedId === null || messages.length === 0) return;
    const page = await fetchMessages(selectedId, {
      beforeId: messages[0]?.id,
      search: search || undefined,
    });
    setOlder((current) => dedupeMessages([...page.messages, ...current]));
    setHasOlder(page.has_more);
  }

  const typingLabel = (conversation?.typing ?? [])
    .map((person) => person.name)
    .join(', ');

  const canCompose = isMember && conversation?.permissions.can_send === true && search === '';

  return (
    <div className="-m-6 flex h-[calc(100vh-4rem)] min-h-0 bg-white">
      <aside className="flex w-80 shrink-0 flex-col border-r border-slate-200">
        <div className="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
          <h1 className="text-base font-semibold text-slate-900">Messages</h1>
          {isAdmin ? (
            <div className="flex gap-1">
              <Button type="button" size="sm" variant="outline" onClick={() => setShowDirect(true)}>
                Chat
              </Button>
              <Button type="button" size="sm" onClick={() => setShowCreate(true)}>
                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                Group
              </Button>
            </div>
          ) : (
            <Button type="button" size="sm" variant="outline" onClick={() => setShowDirect(true)}>
              Message admin
            </Button>
          )}
        </div>
        <div className="space-y-2 border-b border-slate-200 p-3">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={listSearch}
              onChange={(event) => setListSearch(event.target.value)}
              placeholder="Search conversations"
              aria-label="Search conversations"
              className="h-9 w-full rounded-md border border-slate-300 pl-9 pr-3 text-sm"
            />
          </div>
          {isAdmin ? (
            <button
              type="button"
              className="text-xs font-medium text-brand"
              onClick={() => {
                setArchived((current) => !current);
                setSelectedId(null);
              }}
            >
              {archived ? 'Back to active chats' : 'Archived groups'}
            </button>
          ) : null}
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto">
          {conversationsQuery.isLoading ? (
            <p className="px-4 py-6 text-sm text-slate-500">Loading conversations...</p>
          ) : visibleConversations.length === 0 ? (
            <p className="px-4 py-6 text-sm text-slate-500">No conversations yet.</p>
          ) : (
            visibleConversations.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setSelectedId(item.id)}
                className={cn(
                  'flex w-full flex-col gap-1 border-b border-slate-100 px-4 py-3 text-left',
                  selectedId === item.id ? 'bg-brand-50' : 'hover:bg-slate-50',
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-sm font-semibold text-slate-900">{item.name}</span>
                  {item.unread_count > 0 || item.has_unread_mention ? (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-brand px-1.5 py-0.5 text-[10px] font-semibold text-white">
                      {item.has_unread_mention ? <span aria-label="You were mentioned">@</span> : null}
                      {item.unread_count > 0 ? <span>{item.unread_count}</span> : null}
                    </span>
                  ) : null}
                </div>
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-xs text-slate-500">
                    {messagePreview(item.last_message)}
                  </span>
                  <span className="shrink-0 text-[10px] uppercase tracking-wide text-slate-400">
                    {kindLabel(item.kind)}
                  </span>
                </div>
              </button>
            ))
          )}
        </div>
      </aside>

      <section className="flex min-w-0 flex-1 flex-col">
        {conversation === null ? (
          <div className="flex flex-1 items-center justify-center text-sm text-slate-500">
            Select a conversation to start.
          </div>
        ) : (
          <>
            <header className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
              <button type="button" className="min-w-0 text-left" onClick={() => setShowInfo(true)}>
                <p className="truncate text-sm font-semibold text-slate-900">{conversation.name}</p>
                <p className="text-xs text-slate-500">
                  {kindLabel(conversation.kind)}
                  {conversation.kind !== 'direct' ? ` · ${conversation.members_count} people` : ''}
                  {conversation.deleted_at ? ' · Archived' : ''}
                </p>
              </button>
              {isMember ? (
                <div className="relative w-56">
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <input
                    value={threadSearch}
                    onChange={(event) => setThreadSearch(event.target.value)}
                    placeholder="Search in chat"
                    aria-label="Search in chat"
                    className="h-9 w-full rounded-md border border-slate-300 pl-9 pr-3 text-sm"
                  />
                </div>
              ) : null}
            </header>

            {error !== undefined ? (
              <div className="px-4 pt-3">
                <Alert variant="error">{error}</Alert>
              </div>
            ) : null}

            {conversation.permissions.can_join ? (
              <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center">
                <p className="text-sm text-slate-600">
                  You can see this group. Join it to read the history and take part.
                </p>
                <Button
                  type="button"
                  onClick={() => {
                    void joinGroup(conversation.id)
                      .then(() => invalidateMessaging())
                      .catch((caught: unknown) => reportError(caught, 'Unable to join this group.'));
                  }}
                >
                  Join group
                </Button>
              </div>
            ) : conversation.permissions.can_restore ? (
              <div className="flex flex-1 flex-col items-center justify-center gap-3">
                <p className="text-sm text-slate-600">This group is archived. Restoring it brings the history back.</p>
                <Button
                  type="button"
                  onClick={() => {
                    void restoreGroup(conversation.id)
                      .then(() => {
                        setArchived(false);
                        invalidateMessaging();
                      })
                      .catch((caught: unknown) => reportError(caught, 'Unable to restore this group.'));
                  }}
                >
                  Restore group
                </Button>
              </div>
            ) : (
              <>
                <div
                  ref={scrollerRef}
                  onScroll={() => {
                    const node = scrollerRef.current;
                    if (node === null) return;
                    stickRef.current = node.scrollHeight - node.scrollTop - node.clientHeight < 80;
                  }}
                  className="min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4"
                >
                  {hasOlder && search === '' ? (
                    <div className="flex justify-center">
                      <Button type="button" size="sm" variant="outline" onClick={() => void loadOlder()}>
                        Load earlier messages
                      </Button>
                    </div>
                  ) : null}
                  {messagesQuery.isLoading ? (
                    <p className="text-center text-sm text-slate-500">Loading messages...</p>
                  ) : null}
                  {messages.map((message) => {
                    const mine = myId !== null && message.sender?.id === myId;
                    const revealedMessage = revealed[message.id];
                    const display = revealedMessage ?? message;
                    if (message.kind === 'system') {
                      return (
                        <p key={message.id} className="text-center text-xs text-slate-500">
                          {message.placeholder ?? message.body}
                        </p>
                      );
                    }
                    return (
                      <div key={message.id} className={cn('flex', mine ? 'justify-end' : 'justify-start')}>
                        <div
                          className={cn(
                            'max-w-[75%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                            mine ? 'bg-brand text-white' : 'bg-white text-slate-800',
                          )}
                        >
                          {!mine && message.sender ? (
                            <p className="mb-1 text-xs font-semibold text-brand">{message.sender.name}</p>
                          ) : null}
                          {message.reply_to ? (
                            <p className={cn('mb-1 truncate rounded-md px-2 py-1 text-xs', mine ? 'bg-white/15' : 'bg-slate-100')}>
                              {message.reply_to.sender_name}: {message.reply_to.placeholder ?? message.reply_to.body}
                            </p>
                          ) : null}
                          <p className="whitespace-pre-wrap break-words">
                            {display.placeholder ?? display.body}
                          </p>
                          {display.attachments.map((attachment) => (
                            <AttachmentView
                              key={attachment.id}
                              attachment={attachment}
                              reveal={revealedMessage !== undefined}
                            />
                          ))}
                          <div className={cn('mt-1 flex items-center justify-end gap-2 text-[10px]', mine ? 'text-white/80' : 'text-slate-400')}>
                            {message.edited_at ? <span>edited</span> : null}
                            <span>{formatClock(message.created_at)}</span>
                            {mine && message.receipt === 'sent' ? <Check className="h-3 w-3" /> : null}
                            {mine && message.receipt === 'delivered' ? <CheckCheck className="h-3 w-3" /> : null}
                            {mine && message.receipt === 'seen' ? <CheckCheck className="h-3 w-3 text-sky-200" /> : null}
                          </div>
                          <div className="mt-1 flex flex-wrap gap-2 text-[11px]">
                            {canCompose && !message.removed ? (
                              <button type="button" className={mine ? 'text-white/80' : 'text-slate-500'} onClick={() => setReplyTo(message)}>
                                Reply
                              </button>
                            ) : null}
                            {mine && !message.removed ? (
                              <button type="button" className={mine ? 'text-white/80' : 'text-slate-500'} onClick={() => startEdit(message)}>
                                Edit
                              </button>
                            ) : null}
                            {(mine || isAdmin) && !message.removed ? (
                              <button
                                type="button"
                                className={mine ? 'text-white/80' : 'text-red-600'}
                                onClick={() => {
                                  void deleteMessage(message.id)
                                    .then(() => invalidateMessaging())
                                    .catch((caught: unknown) => reportError(caught, 'Unable to delete that message.'));
                                }}
                              >
                                Delete
                              </button>
                            ) : null}
                            {isAdmin && message.removed && revealedMessage === undefined ? (
                              <button
                                type="button"
                                className={mine ? 'text-white/80' : 'text-brand'}
                                onClick={() => {
                                  void revealMessage(message.id)
                                    .then((full) => setRevealed((current) => ({ ...current, [message.id]: full })))
                                    .catch((caught: unknown) => reportError(caught, 'Unable to open that message.'));
                                }}
                              >
                                View original
                              </button>
                            ) : null}
                            {mine && conversation.kind !== 'direct' && message.seen_by.length > 0 ? (
                              <button
                                type="button"
                                className={mine ? 'text-white/80' : 'text-slate-500'}
                                onClick={() => setSeenFor(seenFor === message.id ? null : message.id)}
                              >
                                Seen by {message.seen_by.length}
                              </button>
                            ) : null}
                          </div>
                          {seenFor === message.id ? (
                            <p className={cn('mt-1 text-[11px]', mine ? 'text-white/80' : 'text-slate-500')}>
                              {message.seen_by.map((person) => person.name).join(', ')}
                            </p>
                          ) : null}
                        </div>
                      </div>
                    );
                  })}
                </div>
                {typingLabel !== '' ? (
                  <p className="px-4 py-1 text-xs text-slate-500">{typingLabel} {typingLabel.includes(',') ? 'are' : 'is'} typing...</p>
                ) : null}
                {canCompose ? (
                  <form
                    className="border-t border-slate-200 p-3"
                    onKeyDown={(event) => {
                      if (event.key !== 'Enter' || event.shiftKey || event.nativeEvent.isComposing) {
                        return;
                      }
                      if (!(event.target instanceof HTMLTextAreaElement)) {
                        return;
                      }
                      event.preventDefault();
                      event.currentTarget.requestSubmit();
                    }}
                    onSubmit={(event) => {
                      event.preventDefault();
                      if (sendMutation.isPending) {
                        return;
                      }
                      if (draft.trim() === '' && files.length === 0 && keptAttachmentIds.length === 0) {
                        setError('A message needs text or a file.');
                        return;
                      }
                      sendMutation.mutate();
                    }}
                  >
                    {replyTo ? (
                      <div className="mb-2 flex items-center justify-between rounded-md bg-slate-100 px-3 py-2 text-xs">
                        <span className="truncate">Replying to {replyTo.sender?.name}: {replyTo.body ?? 'Attachment'}</span>
                        <button type="button" onClick={() => setReplyTo(null)} aria-label="Cancel reply"><X className="h-3.5 w-3.5" /></button>
                      </div>
                    ) : null}
                    {editing ? (
                      <div className="mb-2 flex items-center justify-between text-xs text-slate-500">
                        <span>Editing message</span>
                        <button type="button" onClick={() => { setEditing(null); setDraft(''); setKeptAttachmentIds([]); }}>Cancel</button>
                      </div>
                    ) : null}
                    {mentionChoices.length > 0 ? (
                      <div className="mb-2 overflow-hidden rounded-md border border-slate-200 bg-white">
                        {mentionChoices.map((person) => (
                          <button
                            key={`${person.id}-${person.name}`}
                            type="button"
                            className="block w-full px-3 py-2 text-left text-sm hover:bg-brand-50"
                            onClick={() => insertMention(person)}
                          >
                            @{person.name}
                          </button>
                        ))}
                      </div>
                    ) : null}
                    {keptAttachmentIds.length > 0 && editing ? (
                      <div className="mb-2 flex flex-wrap gap-2">
                        {editing.attachments
                          .filter((file) => keptAttachmentIds.includes(file.id))
                          .map((file) => (
                            <button
                              key={file.id}
                              type="button"
                              className="rounded-full bg-slate-100 px-2 py-1 text-xs"
                              onClick={() => setKeptAttachmentIds((current) => current.filter((id) => id !== file.id))}
                            >
                              {file.original_name} ×
                            </button>
                          ))}
                      </div>
                    ) : null}
                    {files.length > 0 ? (
                      <div className="mb-2 flex flex-wrap gap-2">
                        {files.map((file) => (
                          <button
                            key={`${file.name}-${file.size}`}
                            type="button"
                            className="rounded-full bg-slate-100 px-2 py-1 text-xs"
                            onClick={() => setFiles((current) => current.filter((item) => item !== file))}
                          >
                            {file.name} · {formatBytes(file.size)} ×
                          </button>
                        ))}
                      </div>
                    ) : null}
                    <div className="flex items-end gap-2">
                      <input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        className="hidden"
                        onChange={(event) => {
                          chooseFiles(event.target.files);
                          event.target.value = '';
                        }}
                      />
                      <Button type="button" variant="outline" size="sm" onClick={() => fileInputRef.current?.click()} aria-label="Attach files">
                        <Paperclip className="h-4 w-4" />
                      </Button>
                      <textarea
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        rows={2}
                        placeholder="Write a message. Use @ to mention someone."
                        className="min-h-10 flex-1 resize-none rounded-md border border-slate-300 px-3 py-2 text-sm"
                      />
                      <Button type="submit" size="sm" disabled={sendMutation.isPending} aria-label="Send message">
                        {sendMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <SendHorizontal className="h-4 w-4" />}
                      </Button>
                    </div>
                  </form>
                ) : isMember && search !== '' ? (
                  <p className="border-t border-slate-200 px-4 py-3 text-xs text-slate-500">Clear the search to send a message.</p>
                ) : isMember ? (
                  <p className="border-t border-slate-200 px-4 py-3 text-xs text-slate-500">Only admins can send messages in this group.</p>
                ) : null}
              </>
            )}
          </>
        )}
      </section>

      {showCreate && isAdmin ? (
        <CreateGroupDialog
          onClose={() => setShowCreate(false)}
          onCreated={(id) => {
            setShowCreate(false);
            setArchived(false);
            setSelectedId(id);
            invalidateMessaging();
          }}
        />
      ) : null}
      {showDirect ? (
        <DirectDialog
          onClose={() => setShowDirect(false)}
          onOpened={(id) => {
            setShowDirect(false);
            setArchived(false);
            setSelectedId(id);
            invalidateMessaging();
          }}
        />
      ) : null}
      {showInfo && conversation ? (
        <InfoDialog
          conversation={conversation}
          isAdmin={isAdmin === true}
          myId={myId}
          onClose={() => setShowInfo(false)}
          onChanged={invalidateMessaging}
          onError={(caught) => reportError(caught, 'That change could not be saved.')}
        />
      ) : null}
    </div>
  );
}

function CreateGroupDialog({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (id: number) => void;
}) {
  const [kind, setKind] = useState<'announcement' | 'general'>('announcement');
  const [name, setName] = useState('');
  const [permission, setPermission] = useState<SendPermission>('admins_only');
  const [memberIds, setMemberIds] = useState<number[]>([]);
  const [error, setError] = useState<string | undefined>();
  const people = useQuery({ queryKey: ['messaging', 'directory'], queryFn: () => fetchDirectory() });

  useEffect(() => {
    setPermission(kind === 'announcement' ? 'admins_only' : 'all_members');
  }, [kind]);

  return (
    <Modal title="New group" onClose={onClose}>
      {error ? <Alert variant="error">{error}</Alert> : null}
      <label className="block text-sm font-medium text-slate-700">
        Kind
        <select
          value={kind}
          onChange={(event) => setKind(event.target.value as 'announcement' | 'general')}
          className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm"
        >
          <option value="announcement">Announcement</option>
          <option value="general">General</option>
        </select>
      </label>
      <label className="block text-sm font-medium text-slate-700">
        Name
        <input value={name} onChange={(event) => setName(event.target.value)} className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
      </label>
      <label className="block text-sm font-medium text-slate-700">
        Who can send
        <select
          value={permission}
          onChange={(event) => setPermission(event.target.value as SendPermission)}
          className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm"
        >
          <option value="admins_only">Admins only</option>
          <option value="all_members">All members</option>
        </select>
      </label>
      <PersonChecklist
        people={people.data ?? []}
        selected={memberIds}
        onToggle={(id) => setMemberIds((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])}
      />
      <Button
        type="button"
        onClick={() => {
          void createGroup({ kind, name: name.trim(), send_permission: permission, member_ids: memberIds })
            .then((group) => onCreated(group.id))
            .catch((caught: unknown) => setError(getApiErrorMessage(caught, 'Unable to create the group.')));
        }}
        disabled={name.trim() === ''}
      >
        Create group
      </Button>
    </Modal>
  );
}

function DirectDialog({
  onClose,
  onOpened,
}: {
  onClose: () => void;
  onOpened: (id: number) => void;
}) {
  const people = useQuery({ queryKey: ['messaging', 'directory', 'direct'], queryFn: () => fetchDirectory() });
  const [error, setError] = useState<string | undefined>();

  return (
    <Modal title="New message" onClose={onClose}>
      {error ? <Alert variant="error">{error}</Alert> : null}
      <div className="max-h-80 space-y-1 overflow-y-auto">
        {(people.data ?? []).map((person) => (
          <button
            key={person.id}
            type="button"
            className="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm hover:bg-brand-50"
            onClick={() => {
              void openDirect(person.id)
                .then((conversation) => onOpened(conversation.id))
                .catch((caught: unknown) => setError(getApiErrorMessage(caught, 'Unable to open that chat.')));
            }}
          >
            <span>{person.name}</span>
            <span className="text-xs text-slate-400">{person.job_title}</span>
          </button>
        ))}
      </div>
    </Modal>
  );
}

function InfoDialog({
  conversation,
  isAdmin,
  myId,
  onClose,
  onChanged,
  onError,
}: {
  conversation: ConversationSummary;
  isAdmin: boolean;
  myId: number | null;
  onClose: () => void;
  onChanged: () => void;
  onError: (error: unknown) => void;
}) {
  const [name, setName] = useState(conversation.name);
  const [permission, setPermission] = useState<SendPermission>(conversation.send_permission);
  const [selected, setSelected] = useState<number[]>([]);
  const directory = useQuery({
    queryKey: ['messaging', 'directory', conversation.id],
    queryFn: () => fetchDirectory(conversation.id),
    enabled: conversation.permissions.can_manage_members,
  });

  return (
    <Modal title="Group details" onClose={onClose}>
      <p className="text-xs uppercase tracking-wide text-slate-400">{kindLabel(conversation.kind)}</p>
      {conversation.permissions.can_rename ? (
        <label className="block text-sm font-medium text-slate-700">
          Name
          <input value={name} onChange={(event) => setName(event.target.value)} className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
        </label>
      ) : (
        <p className="text-sm font-semibold text-slate-800">{conversation.name}</p>
      )}
      {conversation.permissions.can_change_send_permission ? (
        <label className="block text-sm font-medium text-slate-700">
          Who can send
          <select
            value={permission}
            onChange={(event) => setPermission(event.target.value as SendPermission)}
            className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm"
          >
            <option value="admins_only">Admins only</option>
            <option value="all_members">All members</option>
          </select>
        </label>
      ) : null}
      {(conversation.permissions.can_rename || conversation.permissions.can_change_send_permission) ? (
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            void updateGroup(conversation.id, {
              name: conversation.permissions.can_rename ? name.trim() : undefined,
              send_permission: conversation.permissions.can_change_send_permission ? permission : undefined,
            }).then(onChanged).catch(onError);
          }}
        >
          Save group settings
        </Button>
      ) : null}
      <div>
        <p className="text-sm font-medium text-slate-700">Members</p>
        <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto">
          {(conversation.members ?? []).map((member) => (
            <li key={member.id} className="flex items-center justify-between text-sm">
              <span>{member.name} <span className="text-xs text-slate-400">{member.job_title}</span></span>
              {conversation.permissions.can_manage_members ? (
                <button
                  type="button"
                  className="text-xs text-red-600"
                  onClick={() => {
                    void removeMember(conversation.id, member.id).then(onChanged).catch(onError);
                  }}
                >
                  Remove
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      </div>
      {conversation.permissions.can_manage_members ? (
        <>
          <PersonChecklist
            people={directory.data ?? []}
            selected={selected}
            onToggle={(id) => setSelected((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])}
          />
          <Button
            type="button"
            variant="outline"
            disabled={selected.length === 0}
            onClick={() => {
              void addMembers(conversation.id, selected)
                .then(() => {
                  setSelected([]);
                  onChanged();
                })
                .catch(onError);
            }}
          >
            Add selected people
          </Button>
        </>
      ) : null}
      {isAdmin && myId !== null && conversation.is_member && conversation.kind !== 'direct' ? (
        <Button
          type="button"
          variant="ghost"
          onClick={() => {
            void removeMember(conversation.id, myId).then(() => { onChanged(); onClose(); }).catch(onError);
          }}
        >
          Leave group
        </Button>
      ) : null}
      {conversation.permissions.can_delete ? (
        <Button
          type="button"
          variant="outline"
          className="border-red-200 text-red-700"
          onClick={() => {
            void archiveGroup(conversation.id).then(() => { onChanged(); onClose(); }).catch(onError);
          }}
        >
          <Archive className="h-4 w-4" />
          Archive group
        </Button>
      ) : null}
    </Modal>
  );
}

function PersonChecklist({
  people,
  selected,
  onToggle,
}: {
  people: ChatPerson[];
  selected: number[];
  onToggle: (id: number) => void;
}) {
  if (people.length === 0) return null;
  return (
    <div className="max-h-48 space-y-1 overflow-y-auto rounded-md border border-slate-200 p-2">
      {people.map((person) => (
        <label key={person.id} className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={selected.includes(person.id)}
            onChange={() => onToggle(person.id)}
          />
          <span>{person.name}</span>
          <span className="text-xs text-slate-400">{person.team_name ?? person.job_title}</span>
        </label>
      ))}
    </div>
  );
}

function Modal({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[90vh] w-full max-w-md space-y-4 overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close"><X className="h-4 w-4" /></button>
        </div>
        {children}
      </div>
    </div>
  );
}
