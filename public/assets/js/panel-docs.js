(function () {
	'use strict';

	const esc = window.FHUtil.esc;
	const t = (key, params) => window.t(key, params);

	/**
	 * Minimal Markdown renderer for the documentation shipped with TryHackX Files. Source text is
	 * escaped before the small supported markup subset is emitted.
	 */
	function renderMarkdown(markdown) {
		const lines = String(markdown).replace(/\r\n?/g, '\n').split('\n');
		const output = [];
		let inFence = false;
		let fenceBuffer = [];
		let listType = null;
		let inTable = false;
		let paragraph = [];

		const inline = (source) => esc(source)
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
			.replace(
				/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
				'<a href="$2" target="_blank" rel="noopener">$1</a>'
			)
			.replace(
				/\[([^\]]+)\]\(((?!https?:|javascript:)[^)\s]+)\)/g,
				'<span class="doc-ref">$1</span>'
			);

		const closeList = () => {
			if (!listType) return;
			output.push('</' + listType + '>');
			listType = null;
		};
		const closeParagraph = () => {
			if (!paragraph.length) return;
			output.push('<p>' + inline(paragraph.join(' ')) + '</p>');
			paragraph = [];
		};
		const closeTable = () => {
			if (!inTable) return;
			output.push('</tbody></table></div>');
			inTable = false;
		};
		const closeAll = () => {
			closeParagraph();
			closeList();
			closeTable();
		};

		for (let index = 0; index < lines.length; index++) {
			const line = lines[index];
			if (/^```/.test(line)) {
				if (inFence) {
					output.push('<pre><code>' + esc(fenceBuffer.join('\n')) + '</code></pre>');
					fenceBuffer = [];
					inFence = false;
				} else {
					closeAll();
					inFence = true;
				}
				continue;
			}
			if (inFence) {
				fenceBuffer.push(line);
				continue;
			}
			if (!line.trim()) {
				closeAll();
				continue;
			}
			if (/^\s*(---|\*\*\*|___)\s*$/.test(line)) {
				closeAll();
				output.push('<hr>');
				continue;
			}

			const heading = line.match(/^(#{1,6})\s+(.*)$/);
			if (heading) {
				closeAll();
				const level = Math.min(6, heading[1].length);
				output.push(`<h${level}>${inline(heading[2])}</h${level}>`);
				continue;
			}

			if (/^\s*\|/.test(line) && /^\s*\|[\s:|-]+\|\s*$/.test(lines[index + 1] || '')) {
				closeParagraph();
				closeList();
				closeTable();
				const cells = line.split('|').slice(1, -1)
					.map((cell) => `<th>${inline(cell.trim())}</th>`).join('');
				output.push('<div class="table-wrap"><table><thead><tr>'
					+ cells + '</tr></thead><tbody>');
				inTable = true;
				index++;
				continue;
			}
			if (inTable && /^\s*\|/.test(line)) {
				const cells = line.split('|').slice(1, -1)
					.map((cell) => `<td>${inline(cell.trim())}</td>`).join('');
				output.push('<tr>' + cells + '</tr>');
				continue;
			}
			closeTable();

			const quote = line.match(/^>\s?(.*)$/);
			if (quote) {
				closeParagraph();
				closeList();
				output.push('<blockquote>' + inline(quote[1]) + '</blockquote>');
				continue;
			}

			const item = line.match(/^\s*([-*+]|\d+\.)\s+(.*)$/);
			if (item) {
				closeParagraph();
				const wantedType = /^\d/.test(item[1]) ? 'ol' : 'ul';
				if (listType !== wantedType) {
					closeList();
					output.push('<' + wantedType + '>');
					listType = wantedType;
				}
				output.push('<li>' + inline(item[2]) + '</li>');
				continue;
			}

			closeList();
			paragraph.push(line.trim());
		}

		if (inFence) {
			output.push('<pre><code>' + esc(fenceBuffer.join('\n')) + '</code></pre>');
		}
		closeAll();
		return output.join('\n');
	}

	async function openDocModal(name) {
		const body = document.getElementById('docModalBody');
		const title = document.getElementById('docModalTitle');
		if (!body || !title) return;
		title.textContent = '';
		body.textContent = t('common.loading');
		window.showModal('docModal');

		try {
			const result = await window.FHApi.get('admin_doc', { name });
			if (result && result.success) {
				title.textContent = result.title || '';
				body.innerHTML = renderMarkdown(result.markdown || '');
				body.scrollTop = 0;
				return;
			}
			body.replaceChildren(emptyMessage(
				(result && result.error) || t('panel.doc.load_error')
			));
		} catch {
			body.replaceChildren(emptyMessage(t('common.connection_error')));
		}
	}

	function emptyMessage(message) {
		const paragraph = document.createElement('p');
		paragraph.className = 'empty';
		paragraph.textContent = String(message || '');
		return paragraph;
	}

	window.FHPanelDocs = Object.freeze({ openDocModal, renderMarkdown });
}());
