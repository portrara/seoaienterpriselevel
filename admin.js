jQuery(document).ready(function($) {
    // Tab functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all tabs
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Hide all tab content
        $('.tab-content').hide();
        
        // Show selected tab content
        var target = $(this).attr('href');
        $(target).show();
    });

    // API Key Test
    $('#test-api-key').on('click', function() {
        var $button = $(this);
        var $result = $('#api-test-result');
        var apiKey = $('input[name="kelubricants_openai_api_key"]').val();
        
        if (!apiKey) {
            $result.html('<span class="error">Please enter an API key first</span>');
            return;
        }
        
        $button.prop('disabled', true);
        $result.html('<span class="testing">Testing API connection...</span>');
        
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_test_api',
                api_key: apiKey,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span class="success">✓ API key is valid and working</span>');
                } else {
                    $result.html('<span class="error">✗ ' + response.data + '</span>');
                }
            },
            error: function() {
                $result.html('<span class="error">✗ Connection failed</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Google API Test
    $('#test-google-api').on('click', function() {
        var $button = $(this);
        var $result = $('#google-api-test-result');
        
        $button.prop('disabled', true);
        $result.html('<span class="testing">Testing Google API connection...</span>');
        
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_test_google_api',
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span class="success">✓ ' + response.data + '</span>');
                } else {
                    $result.html('<span class="error">✗ ' + response.data + '</span>');
                }
            },
            error: function() {
                $result.html('<span class="error">✗ Connection failed</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Bulk Preview
    $('#bulk-preview').on('click', function() {
        var postTypes = $('#bulk-post-types').val() || ['post', 'page', 'product'];
        var mode = $('#bulk-mode').val();

        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_bulk_preview',
                post_types: postTypes,
                mode: mode,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayBulkPreview(response.data.posts, response.data.total);
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    });

    // Bulk SEO Generation
    $('#bulk-generate-seo').on('click', function() {
        if (!confirm('This will generate location-based SEO for multiple posts. This may take several minutes. Continue?')) {
            return;
        }

        var postTypes = $('#bulk-post-types').val() || ['post', 'page', 'product'];
        var mode = $('#bulk-mode').val();
        var baseKeywords = $('#bulk-keywords').val();
        var location = $('#bulk-location').val();

        startBulkGeneration(postTypes, mode, baseKeywords, location);
    });

    function displayBulkPreview(posts, total) {
        var html = '<h4>📋 Preview: ' + total + ' posts will be processed</h4>';
        
        if (posts.length === 0) {
            html += '<p>No posts found matching the criteria.</p>';
        } else {
            html += '<div class="bulk-preview-list">';
            posts.forEach(function(post) {
                var statusClass = 'bulk-preview-status ' + post.status;
                var statusText = post.status === 'missing' ? '❌ Missing SEO' : 
                               post.status === 'partial' ? '⚠️ Partial SEO' : '✅ Complete';
                
                html += '<div class="bulk-preview-item">';
                html += '<span class="bulk-preview-title">' + post.title + '</span>';
                html += '<span class="' + statusClass + '">' + statusText + '</span>';
                html += '<div class="bulk-preview-meta">Type: ' + post.type.toUpperCase() + '</div>';
                html += '</div>';
            });
            html += '</div>';
        }

        $('#bulk-preview-results').html(html).show();
    }

    function startBulkGeneration(postTypes, mode, baseKeywords, location) {
        $('#bulk-generate-seo').prop('disabled', true).text('🚀 Generating...');
        $('#bulk-progress').html('<div class="progress-bar"><div class="progress-fill" style="width: 0%"></div></div><p>Starting bulk generation...</p>').show();

        processBulkBatch(postTypes, mode, baseKeywords, location, 0, 0, 0, 0);
    }

    function processBulkBatch(postTypes, mode, baseKeywords, location, offset, totalProcessed, totalSuccess, totalErrors) {
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_bulk_generate',
                post_types: postTypes,
                mode: mode,
                base_keywords: baseKeywords,
                location: location,
                offset: offset,
                batch_size: 5,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var newProcessed = totalProcessed + data.results.length;
                    var newSuccess = totalSuccess + data.success_count;
                    var newErrors = totalErrors + data.error_count;
                    var progress = Math.round((newProcessed / data.total) * 100);

                    // Update progress
                    $('#bulk-progress .progress-fill').css('width', progress + '%');
                    $('#bulk-progress p').html('Processed ' + newProcessed + ' of ' + data.total + ' posts (' + progress + '%) | ✅ ' + newSuccess + ' success | ❌ ' + newErrors + ' errors');

                    // Continue if there are more posts
                    if (data.has_more) {
                        setTimeout(function() {
                            processBulkBatch(postTypes, mode, baseKeywords, location, data.next_offset, newProcessed, newSuccess, newErrors);
                        }, 1000); // 1 second delay between batches
                    } else {
                        // Completed
                        $('#bulk-generate-seo').prop('disabled', false).text('🚀 Generate SEO for All');
                        $('#bulk-progress p').html('✅ Bulk generation completed! Processed ' + newProcessed + ' posts with ' + newSuccess + ' successes and ' + newErrors + ' errors.');
                        
                        if (newErrors > 0) {
                            $('#bulk-progress').append('<p style="color: #dc3232;">Some posts failed to generate. Check the error logs for details.</p>');
                        }
                    }
                } else {
                    $('#bulk-generate-seo').prop('disabled', false).text('🚀 Generate SEO for All');
                    $('#bulk-progress p').html('❌ Error: ' + response.data);
                }
            },
            error: function() {
                $('#bulk-generate-seo').prop('disabled', false).text('🚀 Generate SEO for All');
                $('#bulk-progress p').html('❌ Network error occurred.');
            }
        });
    }

    // Generate Targeted SEO
    $(document).on('click', '#keseo-generate-targeted', function() {
        var $button = $(this);
        var $container = $('#keseo-preview-container');
        var postId = $button.data('post-id');
        
        $button.prop('disabled', true).text('🎯 Generating...');
        
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_generate_targeted',
                post_id: postId,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    displaySEOPreview(response.data, 'Targeted SEO Generated', $container);
                    updateFormFields(response.data);
                } else {
                    $container.html('<div class="keseo-preview error">Failed to generate targeted SEO: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function() {
                $container.html('<div class="keseo-preview error">Error generating targeted SEO</div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('🎯 Generate Targeted SEO');
            }
        });
    });

    // Generate Long-tail SEO
    $(document).on('click', '#keseo-generate-longtail', function() {
        var $button = $(this);
        var $container = $('#keseo-preview-container');
        var postId = $button.data('post-id');
        
        // Check if long-tail keywords are set
        var longtailKeywords = $('textarea[name="keseo_longtail_keywords"]').val();
        if (!longtailKeywords.trim()) {
            alert('Please enter some long-tail keywords first!');
            $('textarea[name="keseo_longtail_keywords"]').focus();
            return;
        }
        
        $button.prop('disabled', true).text('📝 Generating...');
        
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_generate_longtail',
                post_id: postId,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    displaySEOPreview(response.data, 'Long-tail SEO Generated', $container);
                    updateFormFields(response.data);
                } else {
                    $container.html('<div class="keseo-preview error">Failed to generate long-tail SEO: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function() {
                $container.html('<div class="keseo-preview error">Error generating long-tail SEO</div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('📝 Generate Long-tail Focus');
            }
        });
    });

    // Analyze Competition
    $(document).on('click', '#keseo-analyze-competition', function() {
        var $button = $(this);
        var $container = $('#keseo-preview-container');
        var postId = $button.data('post-id');
        
        // Check if focus keyword is set
        var focusKeyword = $('input[name="keseo_focus_keyword"]').val();
        if (!focusKeyword.trim()) {
            alert('Please enter a focus keyword first!');
            $('input[name="keseo_focus_keyword"]').focus();
            return;
        }
        
        $button.prop('disabled', true).text('🔍 Analyzing...');
        
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_analyze_competition',
                post_id: postId,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    displayCompetitionAnalysis(response.data, $container);
                } else {
                    $container.html('<div class="keseo-preview error">Failed to analyze competition: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function() {
                $container.html('<div class="keseo-preview error">Error analyzing competition</div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('🔍 Analyze Competition');
            }
        });
    });

    // Display SEO Preview
    function displaySEOPreview(seo, title, container) {
        var html = '<div class="keseo-preview">';
        html += '<h4>' + title + '</h4>';
        
        if (seo.meta_title) {
            html += '<div class="seo-field"><strong>Title:</strong> ' + seo.meta_title;
            html += ' <small>(' + seo.meta_title.length + '/60 chars)</small></div>';
        }
        
        if (seo.meta_description) {
            html += '<div class="seo-field"><strong>Description:</strong> ' + seo.meta_description;
            html += ' <small>(' + seo.meta_description.length + '/155 chars)</small></div>';
        }
        
        if (seo.focus_keyword) {
            html += '<div class="seo-field"><strong>Focus Keyword:</strong> ' + seo.focus_keyword + '</div>';
        }
        
        if (seo.seo_tags) {
            html += '<div class="seo-field"><strong>Tags:</strong> ' + seo.seo_tags + '</div>';
        }
        
        if (seo.schema_type) {
            html += '<div class="seo-field"><strong>Schema Type:</strong> ' + seo.schema_type + '</div>';
        }
        
        if (seo.strategy_notes) {
            html += '<div class="seo-field"><strong>Strategy Notes:</strong> ' + seo.strategy_notes + '</div>';
        }
        
        if (seo.keywords_used) {
            html += '<div class="seo-field"><strong>Keywords Used:</strong><br>';
            html += '<small>Focus: ' + (seo.keywords_used.focus || 'None') + '<br>';
            html += 'Words: ' + (seo.keywords_used.focused_words || 'None') + '<br>';
            html += 'Long-tail: ' + (seo.keywords_used.longtail || 'None') + '</small></div>';
        }
        
        html += '<button type="button" class="button button-secondary apply-seo-data" style="margin-top: 10px;">Apply to Form</button>';
        html += '</div>';
        
        container.html(html);
    }

    // Display Competition Analysis
    function displayCompetitionAnalysis(analysis, container) {
        var html = '<div class="keseo-preview">';
        html += '<h4>🔍 Competition Analysis</h4>';
        
        html += '<div class="competition-score">';
        html += '<strong>Keyword:</strong> ' + analysis.keyword + '<br>';
        html += '<strong>Difficulty:</strong> <span style="color: ' + analysis.color + '; font-weight: bold;">' + analysis.difficulty_level + '</span>';
        html += ' (' + analysis.difficulty_score + '/100)<br>';
        html += '</div>';
        
        if (analysis.suggestions && analysis.suggestions.length > 0) {
            html += '<div class="suggestions"><strong>Suggestions:</strong><ul>';
            analysis.suggestions.forEach(function(suggestion) {
                html += '<li>' + suggestion + '</li>';
            });
            html += '</ul></div>';
        }
        
        if (analysis.opportunities && analysis.opportunities.length > 0) {
            html += '<div class="opportunities"><strong>Keyword Opportunities:</strong><ul>';
            analysis.opportunities.forEach(function(opportunity) {
                html += '<li>' + opportunity + '</li>';
            });
            html += '</ul></div>';
        }
        
        html += '</div>';
        container.html(html);
    }

    // Update form fields with generated data
    function updateFormFields(seo) {
        if (seo.meta_title) {
            $('input[name="keseo_title"]').val(seo.meta_title).trigger('input');
        }
        if (seo.meta_description) {
            $('textarea[name="keseo_description"]').val(seo.meta_description).trigger('input');
        }
        if (seo.focus_keyword) {
            $('input[name="keseo_focus_keyword"]').val(seo.focus_keyword);
        }
    }

    // Apply SEO data button
    $(document).on('click', '.apply-seo-data', function() {
        var $preview = $(this).closest('.keseo-preview');
        
        // Extract data from preview and apply to form
        var title = $preview.find('.seo-field:contains("Title:")').text().replace(/Title:\s*/, '').replace(/\s*\(\d+\/\d+.*\)/, '');
        var description = $preview.find('.seo-field:contains("Description:")').text().replace(/Description:\s*/, '').replace(/\s*\(\d+\/\d+.*\)/, '');
        var keyword = $preview.find('.seo-field:contains("Focus Keyword:")').text().replace(/Focus Keyword:\s*/, '');
        
        if (title) $('input[name="keseo_title"]').val(title).trigger('input');
        if (description) $('textarea[name="keseo_description"]').val(description).trigger('input');
        if (keyword) $('input[name="keseo_focus_keyword"]').val(keyword);
        
        alert('SEO data applied to form fields!');
    });

    // Legacy SEO Preview Generation (for post edit screen)
    if (typeof keseo_ajax.post_id !== 'undefined' && keseo_ajax.post_id > 0) {
        // Add SEO preview button to post editor if we're on a post edit screen
        var $previewButton = $('<button type="button" id="keseo-preview" class="button button-secondary">Generate SEO Preview</button>');
        var $previewContainer = $('<div id="keseo-preview-container" style="margin-top: 15px;"></div>');
        
        // Try to add to different possible locations
        if ($('#submitdiv .submitbox').length) {
            $('#submitdiv .submitbox').append($previewButton).append($previewContainer);
        } else if ($('.edit-post-sidebar').length) {
            $('.edit-post-sidebar').prepend($('<div class="components-panel"><div class="components-panel__body"><h2>SEO Preview</h2></div></div>').append($previewButton).append($previewContainer));
        }
        
        $previewButton.on('click', function() {
            var $button = $(this);
            var $container = $('#keseo-preview-container');
            
            $button.prop('disabled', true).text('Generating...');
            
            $.ajax({
                url: keseo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'keseo_generate_preview',
                    post_id: keseo_ajax.post_id,
                    nonce: keseo_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        displaySEOPreview(response.data, 'Generated SEO Preview', $container);
                    } else {
                        $container.html('<div class="error">Failed to generate SEO preview</div>');
                    }
                },
                error: function() {
                    $container.html('<div class="error">Error generating SEO preview</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Generate SEO Preview');
                }
            });
        });
    }

    // Auto-save settings when changed
    $('.keseo-auto-save').on('change', function() {
        var $field = $(this);
        var fieldName = $field.attr('name');
        var fieldValue = $field.is(':checkbox') ? ($field.is(':checked') ? '1' : '0') : $field.val();
        
        $.ajax({
            url: keseo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'keseo_save_setting',
                field_name: fieldName,
                field_value: fieldValue,
                nonce: keseo_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $field.closest('tr').addClass('saved').delay(2000).queue(function() {
                        $(this).removeClass('saved').dequeue();
                    });
                }
            }
        });
    });

    // Show/hide Google API fields based on enable checkbox
    $('input[name="keseo_enable_google_validation"]').on('change', function() {
        var isEnabled = $(this).is(':checked');
        $('.google-api-fields').toggle(isEnabled);
    }).trigger('change');

    // Form validation
    $('form').on('submit', function(e) {
        var apiKey = $('input[name="kelubricants_openai_api_key"]').val();
        
        if (!apiKey) {
            alert('Please enter an OpenAI API key before saving settings.');
            e.preventDefault();
            $('input[name="kelubricants_openai_api_key"]').focus();
            return false;
        }
    });

    // Character counters for text fields
    function addCharacterCounter($field, maxLength) {
        var $counter = $('<div class="character-counter"></div>');
        $field.after($counter);
        
        function updateCounter() {
            var length = $field.val().length;
            $counter.text(length + '/' + maxLength);
            $counter.toggleClass('over-limit', length > maxLength);
        }
        
        $field.on('input', updateCounter);
        updateCounter();
    }

    // Add character counters to text fields
    $('input[name="keseo_focus_keywords"]').each(function() {
        addCharacterCounter($(this), 200);
    });

    // Tooltip functionality
    $('.tooltip').on('mouseenter', function() {
        var tooltipText = $(this).data('tooltip');
        var $tooltip = $('<div class="tooltip-popup">' + tooltipText + '</div>');
        $('body').append($tooltip);
        
        var offset = $(this).offset();
        $tooltip.css({
            top: offset.top - $tooltip.outerHeight() - 5,
            left: offset.left + ($(this).outerWidth() / 2) - ($tooltip.outerWidth() / 2)
        });
    }).on('mouseleave', function() {
        $('.tooltip-popup').remove();
    });
});