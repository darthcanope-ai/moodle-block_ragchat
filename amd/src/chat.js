// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * RAG chatbot AMD module.
 *
 * Handles user interaction with the block_ragchat chat widget:
 *  - Submit a question via the Moodle AJAX external-function framework.
 *  - Render the AI answer and its source list.
 *  - Display a loading indicator while waiting.
 *
 * @module     block_ragchat/chat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Initialise the chat widget for a given block instance.
 *
 * @param {Object} config
 * @param {number} config.blockid      Block instance ID.
 * @param {number} config.courseid     Moodle course ID (1 = site).
 * @param {string} config.collectionid Albert collection identifier.
 * @param {Object} config.strings      Localised UI strings.
 */
export const init = (config) => {
    const root = document.querySelector(`#block-ragchat-${config.blockid}`);
    if (!root) {
        return;
    }

    const form      = root.querySelector('.ragchat-form');
    const input     = root.querySelector('.ragchat-input');
    const messages  = root.querySelector('.ragchat-messages');
    const sendBtn   = root.querySelector('.ragchat-send');

    if (!form || !input || !messages || !sendBtn) {
        return;
    }

    form.addEventListener('submit', async(e) => {
        e.preventDefault();

        const question = input.value.trim();
        if (!question) {
            return;
        }

        // Append user bubble.
        appendMessage(messages, 'user', escapeHtml(question));
        input.value = '';
        setLoading(sendBtn, input, true, config.strings.thinking);

        try {
            const [result] = await Ajax.call([{
                methodname: 'block_ragchat_send_message',
                args: {
                    question:     question,
                    collectionid: config.collectionid,
                    courseid:     config.courseid,
                },
            }]);

            if (!result.success) {
                appendMessage(messages, 'error', escapeHtml(result.error || config.strings.error_generic));
                return;
            }

            appendAnswer(messages, result.answer, result.sources, config.strings);

        } catch (err) {
            Notification.exception(err);
            appendMessage(messages, 'error', config.strings.error_generic);
        } finally {
            setLoading(sendBtn, input, false, config.strings.send);
            scrollToBottom(messages);
        }
    });
};

// -----------------------------------------------------------------------------
// Private helpers
// -----------------------------------------------------------------------------

/**
 * Append a simple text bubble (user message or error).
 *
 * @param {Element} container
 * @param {string}  role      'user' | 'error'
 * @param {string}  text      Already-escaped HTML.
 */
const appendMessage = (container, role, text) => {
    const div = document.createElement('div');
    div.className = `ragchat-bubble ragchat-bubble--${role}`;
    div.innerHTML = text;
    container.appendChild(div);
    scrollToBottom(container);
};

/**
 * Append the AI answer bubble with optional source list.
 *
 * @param {Element} container
 * @param {string}  answer    Raw answer text from the LLM.
 * @param {Array}   sources   Source objects [{title, excerpt, url, score}].
 * @param {Object}  strings   Localised strings.
 */
const appendAnswer = (container, answer, sources, strings) => {
    const div = document.createElement('div');
    div.className = 'ragchat-bubble ragchat-bubble--assistant';

    // Answer text (preserve line breaks).
    const answerEl = document.createElement('p');
    answerEl.className = 'ragchat-answer';
    answerEl.innerHTML = escapeHtml(answer).replace(/\n/g, '<br>');
    div.appendChild(answerEl);

    // Sources list.
    if (sources && sources.length > 0) {
        const sourcesEl = document.createElement('details');
        sourcesEl.className = 'ragchat-sources';

        const summary = document.createElement('summary');
        summary.textContent = strings.sources;
        sourcesEl.appendChild(summary);

        const ul = document.createElement('ul');
        sources.forEach((src) => {
            const li = document.createElement('li');
            const titleEl = src.url
                ? `<a href="${escapeHtml(src.url)}" target="_blank" rel="noopener">${escapeHtml(src.title)}</a>`
                : escapeHtml(src.title);
            li.innerHTML = `${titleEl}<br><small>${escapeHtml(src.excerpt)}</small>`;
            ul.appendChild(li);
        });
        sourcesEl.appendChild(ul);
        div.appendChild(sourcesEl);
    }

    // AI disclaimer.
    const disclaimer = document.createElement('p');
    disclaimer.className = 'ragchat-disclaimer';
    disclaimer.textContent = strings.ai_disclaimer;
    div.appendChild(disclaimer);

    container.appendChild(div);
};

/**
 * Toggle the loading state of the send button and input field.
 *
 * @param {Element} btn
 * @param {Element} input
 * @param {boolean} loading
 * @param {string}  label
 */
const setLoading = (btn, input, loading, label) => {
    btn.disabled   = loading;
    input.disabled = loading;
    btn.textContent = label;
};

/**
 * Scroll a container to its bottom.
 *
 * @param {Element} el
 */
const scrollToBottom = (el) => {
    el.scrollTop = el.scrollHeight;
};

/**
 * Escape a string for safe insertion as HTML text.
 *
 * @param  {string} str
 * @return {string}
 */
const escapeHtml = (str) => {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};
