(function () {
    'use strict';

    const eventTypes = ['click', 'submit', 'change', 'input', 'keydown', 'error', 'load'];
    const dataAttribute = (type) => `data-fh-${type}`;

    function splitTopLevel(source, separator) {
        const parts = [];
        let start = 0;
        let quote = '';
        let escaped = false;
        let depth = 0;
        for (let index = 0; index < source.length; index += 1) {
            const character = source[index];
            if (quote) {
                if (escaped) {
                    escaped = false;
                } else if (character === '\\') {
                    escaped = true;
                } else if (character === quote) {
                    quote = '';
                }
                continue;
            }
            if (character === "'" || character === '"') {
                quote = character;
            } else if (character === '(') {
                depth += 1;
            } else if (character === ')') {
                depth -= 1;
                if (depth < 0) throw new Error('Unbalanced handler expression.');
            } else if (character === separator && depth === 0) {
                parts.push(source.slice(start, index));
                start = index + 1;
            }
        }
        if (quote || depth !== 0) throw new Error('Unbalanced handler expression.');
        parts.push(source.slice(start));
        return parts;
    }

    function parseQuoted(source) {
        const quote = source[0];
        if ((quote !== "'" && quote !== '"') || source.at(-1) !== quote) {
            throw new Error('Invalid quoted argument.');
        }
        let value = '';
        for (let index = 1; index < source.length - 1; index += 1) {
            const character = source[index];
            if (character !== '\\') {
                value += character;
                continue;
            }
            index += 1;
            if (index >= source.length - 1) throw new Error('Invalid escape.');
            const escaped = source[index];
            const escapes = { n: '\n', r: '\r', t: '\t', b: '\b', f: '\f', v: '\v' };
            value += Object.hasOwn(escapes, escaped) ? escapes[escaped] : escaped;
        }
        return value;
    }

    function parseArgument(source, event, element) {
        const value = source.trim();
        if (!value) throw new Error('Empty handler argument.');
        if (value === 'event') return event;
        if (value === 'this') return element;
        if (value === 'this.checked') return Boolean(element.checked);
        if (value === 'true') return true;
        if (value === 'false') return false;
        if (value === 'null') return null;
        if (/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value)) return Number(value);
        if (value.startsWith("'") || value.startsWith('"')) return parseQuoted(value);

        const field = value.match(
            /^document\.getElementById\((['"])([A-Za-z][A-Za-z0-9_:-]*)\1\)\.value$/
        );
        if (field) return document.getElementById(field[2])?.value || '';
        throw new Error('Unsupported handler argument.');
    }

    function invokeCall(expression, event, element) {
        const call = expression.match(/^([A-Za-z_$][A-Za-z0-9_$]*)\((.*)\)$/s);
        if (!call) throw new Error('Unsupported handler statement.');
        const actions = window.FHPanelActions || {};
        const action = actions[call[1]];
        if (!Object.hasOwn(actions, call[1]) || typeof action !== 'function') {
            throw new Error('Unknown panel action.');
        }
        const rawArguments = call[2].trim();
        const args = rawArguments === ''
            ? []
            : splitTopLevel(rawArguments, ',')
                .map((argument) => parseArgument(argument, event, element));
        return action(...args);
    }

    function execute(source, event, element) {
        for (const rawStatement of splitTopLevel(source, ';')) {
            const statement = rawStatement.trim();
            if (!statement) continue;
            if (statement === 'event.preventDefault()') {
                event.preventDefault();
                continue;
            }
            if (statement === 'event.stopPropagation()') {
                event.stopPropagation();
                continue;
            }
            if (statement === 'return false' || statement === 'return false;') {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            if (statement === 'this.select()') {
                element.select?.();
                continue;
            }
            if (statement === 'this.remove()') {
                element.remove();
                continue;
            }
            if (statement === "this.parentNode.style.display='none'"
                || statement === 'this.parentNode.style.display="none"') {
                if (element.parentNode instanceof HTMLElement) {
                    element.parentNode.hidden = true;
                }
                continue;
            }
            invokeCall(statement, event, element);
        }
    }

    function reportRejectedHandler(error) {
        if (window.FHUi?.toast) {
            window.FHUi.toast(window.t?.('common.error') || 'Action unavailable.', 'error');
        }
        console.error('Rejected declarative panel handler:', error);
    }

    function eventElement(event, type) {
        if (!(event.target instanceof Element)) return null;
        const selector = `[${dataAttribute(type)}]`;
        return type === 'error' || type === 'load'
            ? (event.target.matches(selector) ? event.target : null)
            : event.target.closest(selector);
    }

    for (const type of eventTypes) {
        document.addEventListener(type, (event) => {
            const element = eventElement(event, type);
            if (!element) return;
            const source = element.getAttribute(dataAttribute(type));
            if (!source) return;
            try {
                execute(source, event, element);
            } catch (error) {
                reportRejectedHandler(error);
            }
        }, type === 'error' || type === 'load');
    }

    window.FHPanelEvents = Object.freeze({
        eventTypes: Object.freeze(eventTypes.slice())
    });
}());
