/**
 * NETXIA Chatbot Widget
 * Conecta con php/chatbot.php → Claude API
 */
'use strict';

(function NetxiaChatbot() {
  const btn     = document.querySelector('.chatbot-btn');
  const window_ = document.querySelector('.chatbot-window');
  const messages= document.querySelector('.chat-messages');
  const input   = document.querySelector('.chat-input-wrap textarea');
  const sendBtn = document.querySelector('.chat-send-btn');
  const typing  = document.querySelector('.chat-typing');

  if (!btn || !window_ || !messages || !input) return;

  let history = [];
  let open    = false;
  let sending = false;

  // Mensaje de bienvenida
  const WELCOME = `¡Hola! 👋 Soy el asistente virtual de **Netxia**. Puedo ayudarte con:\n\n• Información sobre nuestros servicios\n• Casos de uso de IA y ciberseguridad\n• Proceso para solicitar una cotización\n\n¿En qué puedo ayudarte hoy?`;

  // Toggle chatbot
  const toggleChat = () => {
    open = !open;
    btn.classList.toggle('open', open);
    window_.classList.toggle('open', open);
    if (open && messages.children.length === 0) {
      addMessage('bot', WELCOME);
      input.focus();
    }
    if (open) input.focus();
  };

  btn.addEventListener('click', toggleChat);

  // Cerrar con ESC
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && open) toggleChat();
  });

  // Enviar mensaje
  const sendMessage = async () => {
    const text = input.value.trim();
    if (!text || sending) return;

    sending = true;
    input.value = '';
    input.style.height = 'auto';

    addMessage('user', text);
    history.push({ role: 'user', content: text });

    showTyping(true);

    try {
      const res = await fetch('./php/chatbot.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ message: text, history: history.slice(-8) }),
      });
      const data = await res.json();

      showTyping(false);

      if (data.success) {
        addMessage('bot', data.reply);
        history.push({ role: 'assistant', content: data.reply });
      } else {
        addMessage('bot', data.message || 'Lo siento, hubo un error. Intenta de nuevo o contáctanos directamente a contacto@netxia.cl');
      }
    } catch {
      showTyping(false);
      addMessage('bot', 'Sin conexión. Contáctanos a contacto@netxia.cl o al +56 9 8902 4643.');
    } finally {
      sending = false;
    }
  };

  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  // Auto-resize textarea
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });

  // ── Helpers ─────────────────────────────────────────────────────────
  function addMessage(role, text) {
    const div  = document.createElement('div');
    div.className = `chat-msg ${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    bubble.innerHTML = parseMarkdown(text);

    const time = document.createElement('div');
    time.className = 'msg-time';
    time.textContent = new Date().toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' });

    div.appendChild(bubble);
    div.appendChild(time);
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function showTyping(show) {
    typing.classList.toggle('show', show);
    if (show) messages.scrollTop = messages.scrollHeight;
  }

  // Mini markdown: bold, bullet lists, line breaks
  function parseMarkdown(text) {
    return text
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/^• (.+)$/gm, '<li>$1</li>')
      .replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>')
      .replace(/\n/g, '<br>');
  }
})();
