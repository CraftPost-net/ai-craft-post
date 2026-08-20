(function () {
    'use strict';

    var box = document.getElementById('ai-craft-post-faq-schema-box');
    if (!box) {
        return;
    }

    var items = document.getElementById('ai-craft-post-faq-schema-items');
    var titleInput = document.getElementById('ai-craft-post-faq-title');
    var addButton = document.getElementById('ai-craft-post-add-faq-item');
    var moveButton = document.getElementById('ai-craft-post-move-faq-from-content');
    var status = document.getElementById('ai-craft-post-faq-status');
    var i18n = window.craftpostFaqI18n || {
        question: 'Question',
        answer: 'Answer',
        remove: 'Remove',
        notFound: 'FAQ block was not found in content.',
        movedSuffix: 'question(s) moved. Save or update the post to keep changes.'
    };

    function renumberItems() {
        if (!items) {
            return;
        }
        var rows = items.querySelectorAll('.ai-craft-post-faq-schema-item');
        rows.forEach(function (row, index) {
            var question = row.querySelector('[data-faq-field="question"]');
            var answer = row.querySelector('[data-faq-field="answer"]');
            if (question) {
                question.name = 'ai_craft_post_faq_schema[' + index + '][question]';
            }
            if (answer) {
                answer.name = 'ai_craft_post_faq_schema[' + index + '][answer]';
            }
        });
    }

    function createItem(questionValue, answerValue) {
        if (!items) {
            return;
        }
        var row = document.createElement('div');
        row.className = 'ai-craft-post-faq-schema-item';
        row.style.cssText = 'margin: 0 0 14px; padding: 12px; border: 1px solid #dcdcde; background: #fff;';
        
        var qLabel = document.createElement('label');
        var qStrong = document.createElement('strong');
        qStrong.textContent = i18n.question;
        qLabel.appendChild(qStrong);
        var qInput = document.createElement('input');
        qInput.type = 'text';
        qInput.setAttribute('data-faq-field', 'question');
        qInput.className = 'widefat';
        qInput.value = questionValue || '';
        qLabel.appendChild(qInput);
        var p1 = document.createElement('p');
        p1.style.marginTop = '0';
        p1.appendChild(qLabel);

        var aLabel = document.createElement('label');
        var aStrong = document.createElement('strong');
        aStrong.textContent = i18n.answer;
        aLabel.appendChild(aStrong);
        var aTextarea = document.createElement('textarea');
        aTextarea.setAttribute('data-faq-field', 'answer');
        aTextarea.rows = 4;
        aTextarea.className = 'widefat';
        aTextarea.value = answerValue || '';
        aLabel.appendChild(aTextarea);
        var p2 = document.createElement('p');
        p2.appendChild(aLabel);

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'button ai-craft-post-remove-faq-item';
        removeBtn.textContent = i18n.remove;

        row.appendChild(p1);
        row.appendChild(p2);
        row.appendChild(removeBtn);

        items.appendChild(row);
        renumberItems();
    }

    function stripTags(html) {
        var container = document.createElement('div');
        container.innerHTML = html || '';
        return (container.textContent || container.innerText || '').replace(/\s+/g, ' ').trim();
    }

    function getEditorContent() {
        if (window.wp && wp.data && wp.data.select('core/editor')) {
            return wp.data.select('core/editor').getEditedPostContent();
        }

        if (window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) {
            return tinyMCE.get('content').getContent();
        }

        var textarea = document.getElementById('content');
        return textarea ? textarea.value : '';
    }

    function setEditorContent(content) {
        if (window.wp && wp.data && wp.data.dispatch('core/editor')) {
            wp.data.dispatch('core/editor').editPost({ content: content });
            return;
        }

        if (window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) {
            tinyMCE.get('content').setContent(content);
            return;
        }

        var textarea = document.getElementById('content');
        if (textarea) {
            textarea.value = content;
        }
    }

    function extractFaqFromContent(content) {
        var headingPattern = /((?:<!--\s*wp:heading(?:\s+\{.*?\})?\s*-->\s*)?<h2\b[^>]*>[\s\S]*?<\/h2>\s*(?:<!--\s*\/wp:heading\s*-->\s*)?)/gi;
        var headings = [];
        var match;
        while ((match = headingPattern.exec(content)) !== null) {
            headings.push({
                html: match[0],
                index: match.index,
                end: match.index + match[0].length,
                text: stripTags(match[0]).toLowerCase()
            });
        }

        var faqHeadingIndex = -1;
        for (var i = 0; i < headings.length; i++) {
            if (/\bfaq\b|поширен|част[іи]|питан|вопрос|часто задаваем/u.test(headings[i].text)) {
                faqHeadingIndex = i;
                break;
            }
        }

        if (faqHeadingIndex < 0) {
            return { content: content, items: [], title: '' };
        }

        var start = headings[faqHeadingIndex].index;
        var end = headings[faqHeadingIndex + 1] ? headings[faqHeadingIndex + 1].index : content.length;
        var section = content.slice(start, end);
        var title = stripTags(headings[faqHeadingIndex].html);
        var questionPattern = /((?:<!--\s*wp:heading(?:\s+\{.*?\})?\s*-->\s*)?<h[34]\b[^>]*>[\s\S]*?<\/h[34]>\s*(?:<!--\s*\/wp:heading\s*-->\s*)?)/gi;
        var questions = [];
        while ((match = questionPattern.exec(section)) !== null) {
            questions.push({
                html: match[0],
                index: match.index,
                end: match.index + match[0].length,
                text: stripTags(match[0])
            });
        }

        var extractedItems = [];
        var removeRanges = [];
        if (questions.length) {
            removeRanges.push({ start: 0, end: questions[0].index });
        }
        questions.forEach(function (question, index) {
            var answerStart = question.end;
            var answerEnd = questions[index + 1] ? questions[index + 1].index : section.length;
            var answerSource = section.slice(answerStart, answerEnd);
            var answer = answerSource.trim();
            var firstAnswerBlock = answerSource.match(/(?:<!--\s*wp:(?:paragraph|list)(?:\s+\{[\s\S]*?\})?\s*-->\s*)?(?:<p\b[^>]*>[\s\S]*?<\/p>|<ul\b[^>]*>[\s\S]*?<\/ul>|<ol\b[^>]*>[\s\S]*?<\/ol>)(?:\s*<!--\s*\/wp:(?:paragraph|list)\s*-->)?/i);
            var removeEnd = answerStart;
            if (firstAnswerBlock) {
                answer = firstAnswerBlock[0].trim();
                removeEnd = answerStart + firstAnswerBlock.index + firstAnswerBlock[0].length;
            } else {
                removeEnd = answerEnd;
            }
            if (question.text && stripTags(answer)) {
                extractedItems.push({
                    question: question.text,
                    answer: answer
                });
                removeRanges.push({ start: question.index, end: removeEnd });
            }
        });

        if (!extractedItems.length) {
            return { content: content, items: [], title: '' };
        }

        var cleanedSection = '';
        var cursor = 0;
        removeRanges.sort(function (a, b) {
            return a.start - b.start;
        }).forEach(function (range) {
            cleanedSection += section.slice(cursor, range.start);
            cursor = Math.max(cursor, range.end);
        });
        cleanedSection += section.slice(cursor);

        return {
            content: (content.slice(0, start) + cleanedSection + content.slice(end)).trim(),
            items: extractedItems,
            title: title
        };
    }

    if (items) {
        items.querySelectorAll('input[name*="[question]"]').forEach(function (input) {
            input.setAttribute('data-faq-field', 'question');
        });
        items.querySelectorAll('textarea[name*="[answer]"]').forEach(function (textarea) {
            textarea.setAttribute('data-faq-field', 'answer');
        });

        items.addEventListener('click', function (event) {
            if (!event.target.classList.contains('ai-craft-post-remove-faq-item')) {
                return;
            }

            var itemRow = event.target.closest('.ai-craft-post-faq-schema-item');
            if (itemRow) {
                itemRow.remove();
                renumberItems();
            }
        });
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            createItem('', '');
        });
    }

    if (moveButton) {
        moveButton.addEventListener('click', function () {
            var result = extractFaqFromContent(getEditorContent());
            if (!result.items.length) {
                if (status) {
                    status.textContent = i18n.notFound;
                }
                return;
            }

            result.items.forEach(function (item) {
                createItem(item.question, item.answer);
            });
            if (result.title && titleInput) {
                titleInput.value = result.title;
            }
            setEditorContent(result.content);
            if (status) {
                status.textContent = result.items.length + ' ' + i18n.movedSuffix;
            }
        });
    }
}());
