<?php
/*
Plugin Name: WebberSites Image Editor AI
Description: A simplified prompt‑driven photo editor with a dark UI that stores generated images in the WordPress media library. Provides an admin editor under the Media menu.
Version: 1.6
Author: Auto Generated
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Start a PHP session to track a unique editor session. WordPress does not
 * start sessions by default, so we open one here when needed. Each user
 * session is identified by a random ID stored in a cookie (`ppe_software_session`).
 */
function ppe_start_session() {
    if (!session_id()) {
        session_start();
    }
    // Create a cookie to track the session ID if it doesn't exist.
    if (empty($_COOKIE['ppe_software_session'])) {
        $sid = bin2hex(random_bytes(8));
        setcookie('ppe_software_session', $sid, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
    } else {
        // Sanitize the incoming session ID to avoid path traversal
        $sid = preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE['ppe_software_session']);
    }
    $_SESSION['ppe_session_id'] = $sid;
}
add_action('init', 'ppe_start_session', 1);

/**
 * Register a backend admin page under the Media menu for the AI Image Editor.
 */
function ppe_register_admin_page() {
    add_menu_page(
        __( 'WebberSites Image Editor AI', 'pocket-photo-editor' ), // Page title
        __( 'WebberSites Image Editor AI', 'pocket-photo-editor' ), // Menu title
        'upload_files', // Capability
        'webbersites-image-editor-ai', // Menu slug
        'ppe_render_admin_page', // Callback function
        'dashicons-format-image', // Icon
        25 // Position near the top of the menu
    );
}
add_action('admin_menu', 'ppe_register_admin_page');

/**
 * Add a custom row action to media library list view. Adds an “AI Image Editor”
 * link for image attachments that navigates to the editor page with the
 * attachment pre-selected.
 *
 * @param array $actions Existing row actions.
 * @param WP_Post $post The current attachment.
 * @param bool $detached Whether the attachment is unattached.
 * @return array Modified row actions.
 */
function ppe_add_media_row_action($actions, $post, $detached) {
    if (strpos($post->post_mime_type, 'image/') === 0) {
        $url = add_query_arg([
            'page' => 'webbersites-image-editor-ai',
            'attachment_id' => $post->ID,
        ], admin_url('admin.php'));
        $actions['ai_image_editor'] = '<a href="' . esc_url($url) . '">' . esc_html__('WebberSites Image Editor AI', 'pocket-photo-editor') . '</a>';
    }
    return $actions;
}
add_filter('media_row_actions', 'ppe_add_media_row_action', 10, 3);

/**
 * Register a settings page under the general Settings menu to allow
 * administrators to enter and store the Gemini API key used by the editor.
 */
function ppe_register_settings_page() {
    add_options_page(
        __( 'WebberSites Image Editor AI Settings', 'pocket-photo-editor' ),
        __( 'WebberSites Image Editor AI', 'pocket-photo-editor' ),
        'manage_options',
        'webbersites-image-editor-ai-settings',
        'ppe_render_settings_page'
    );
}
add_action('admin_menu', 'ppe_register_settings_page');

/**
 * Render the settings page. Provides a form for entering the Gemini API key.
 * On submission the key is saved in the WordPress options table. Only
 * administrators can view and modify this page.
 */
function ppe_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'pocket-photo-editor'));
    }
    // Check if form has been submitted
    if (isset($_POST['ppe_api_key_nonce']) && wp_verify_nonce($_POST['ppe_api_key_nonce'], 'ppe_api_key_save')) {
        $api_key = sanitize_text_field($_POST['ppe_api_key'] ?? '');
        $api_model = sanitize_text_field($_POST['ppe_api_model'] ?? '');
        update_option('ppe_api_key', $api_key);
        update_option('ppe_api_model', $api_model);
        echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'pocket-photo-editor') . '</p></div>';
    }
    $current_key = get_option('ppe_api_key', '');
    $current_model = get_option('ppe_api_model', 'gemini-3-pro-image-preview');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Image Editor Settings', 'pocket-photo-editor'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('ppe_api_key_save', 'ppe_api_key_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ppe_api_key"><?php esc_html_e('Gemini API Key', 'pocket-photo-editor'); ?></label></th>
                    <td>
                        <input name="ppe_api_key" type="text" id="ppe_api_key" value="<?php echo esc_attr($current_key); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Enter your Gemini API key here.', 'pocket-photo-editor'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppe_api_model"><?php esc_html_e('Gemini Model', 'pocket-photo-editor'); ?></label></th>
                    <td>
                        <input name="ppe_api_model" type="text" id="ppe_api_model" value="<?php echo esc_attr($current_model); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Enter the Gemini model to use, for example gemini-3-pro-image-preview.', 'pocket-photo-editor'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Render the admin page for the AI Image Editor. Uses the same UI as the
 * shortcode, but removes the file upload area and adds a button to select
 * an image from the media library. Requires wp_enqueue_media() to load
 * WordPress' media uploader.
 */
function ppe_render_admin_page() {
    if (!current_user_can('upload_files')) {
        wp_die(__('You do not have permission to access this page.', 'pocket-photo-editor'));
    }
    wp_enqueue_media();
    $nonce = wp_create_nonce('ppe_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    // Determine if an attachment ID was passed via query string for preloading
    $initial_attachment_id = isset($_GET['attachment_id']) ? intval($_GET['attachment_id']) : 0;
    ?>
    <div class="editor-wrap">
        <h1><?php esc_html_e( 'WebberSites Image Editor AI', 'pocket-photo-editor' ); ?></h1>
        <!-- Load Font Awesome for tool icons -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
        <!-- Load our customised vanilla ruler script.  This script defines a
             global `Ruler` object with `create` and `clear` methods.  The
             script is bundled with the plugin and has been tailored to the
             dark theme of the editor. -->
        <script src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'ruler-vanilla.js' ); ?>"></script>

        <!-- Remove Cropper.js: cropping is implemented with a custom overlay -->

        <!-- Note: We previously attempted to use third‑party ruler libraries,
             but they either required additional dependencies or created
             duplicate instances when the DOM updated.  Our customised
             vanilla ruler implementation avoids those issues and integrates
             seamlessly with the dark UI. -->
        <div class="wrap">
            <!-- Left: Selector & Viewer -->
            <div class="ppe-card">
                <div class="grid2" style="display:grid; grid-template-columns:1fr; gap:18px;">
                    <div>
                        <button id="selectImageBtn" class="btn" type="button"><?php esc_html_e( 'Select Image from Media Library', 'pocket-photo-editor' ); ?></button>
                        <div id="uploadMsg" class="help" style="margin-top:10px"></div>
                    </div>
                    <div style="position:relative; display:flex; gap:0; align-items:flex-start;">
                        <!-- Tool bar: vertical buttons using Font Awesome icons -->
                        <div class="topButtons" id="topButtons" style="display:none;">
                            <!-- Pan tool -->
                            <button id="panBtn" class="btn btn-small" type="button" title="Pan">
                                <i class="fas fa-hand-paper"></i>
                            </button>
                            <!-- Zoom in tool -->
                            <button id="zoomInBtn" class="btn btn-small" type="button" title="Zoom In">
                                <i class="fas fa-magnifying-glass-plus"></i>
                            </button>
                            <!-- Zoom out tool -->
                            <button id="zoomOutBtn" class="btn btn-small" type="button" title="Zoom Out">
                                <i class="fas fa-magnifying-glass-minus"></i>
                            </button>
                            <!-- Crop tool -->
                            <button id="cropBtn" class="btn btn-small" type="button" title="Crop">
                                <i class="fas fa-crop"></i>
                            </button>
                            <!-- Confirm crop -->
                            <button id="cropConfirmBtn" class="btn btn-small" type="button" title="Confirm Crop" style="display:none;">
                                <i class="fas fa-check"></i>
                            </button>
                            <!-- Cancel crop -->
                            <button id="cropCancelBtn" class="btn btn-small" type="button" title="Cancel Crop" style="display:none;">
                                <i class="fas fa-xmark"></i>
                            </button>
                            <!-- Download current image -->
                            <a id="downloadBtn" class="btn btn-small" href="#" download title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                            <!-- Undo to previous version -->
                            <button id="undoBtn" class="btn btn-small" type="button" title="Undo">
                                <i class="fas fa-rotate-left"></i>
                            </button>
                        </div>
                        <!-- Viewer container -->
                        <div id="viewer" class="viewer">
                            <!-- Ruler container holds the image wrapper and will be the element
                                 that the ruler plugin attaches to. Separating this from the
                                 outer viewer prevents duplicate initializations and keeps the
                                 toolbar and rulers independent. -->
                            <div id="rulerContainer" class="rulerContainer">
                                <!-- Image wrapper holds the current image or skeleton -->
                                <div id="imageWrapper" class="imageWrapper">
                                    <div class="help" style="padding:12px"><?php esc_html_e( 'Select an image to start', 'pocket-photo-editor' ); ?></div>
                                </div>
                            </div>
                        </div>
                        <!-- Floating panel with adjustment sliders -->
                        <div id="adjustPanel" class="adjustPanel" style="display:none;">
                            <div class="adjustHeader" style="cursor:move; font-weight:600; margin-bottom:8px;">Adjustments</div>
                            <div class="sliderRow"><label for="expSlider">Exposure</label><input id="expSlider" type="range" min="-100" max="100" value="0" /></div>
                            <div class="sliderRow"><label for="contrastSlider">Contrast</label><input id="contrastSlider" type="range" min="-100" max="100" value="0" /></div>
                            <div class="sliderRow"><label for="highlightSlider">Highlights</label><input id="highlightSlider" type="range" min="-100" max="100" value="0" /></div>
                            <div class="sliderRow"><label for="shadowSlider">Shadows</label><input id="shadowSlider" type="range" min="-100" max="100" value="0" /></div>
                            <div class="sliderRow"><label for="whitesSlider">Whites</label><input id="whitesSlider" type="range" min="-100" max="100" value="0" /></div>
                            <div class="sliderRow"><label for="blacksSlider">Blacks</label><input id="blacksSlider" type="range" min="-100" max="100" value="0" /></div>
                            <div class="adjustButtons" style="display:flex; gap:6px; margin-top:8px;">
                                <button id="resetAdjustBtn" type="button" class="btn btn-small" style="flex:1;">Reset</button>
                                <button id="applyAdjustBtn" type="button" class="btn btn-small" style="flex:1;">Apply</button>
                            </div>
                        </div>
                    </div>
                    <!-- Error box below viewer and toolbar -->
                    <div id="errorBox" class="error" style="display:none; margin-top:10px;"></div>
                </div>
            </div>
            <!-- Right: History -->
            <div class="ppe-card" style="overflow:auto; max-height: calc(100vh - 200px)">
                <div class="hRow" style="margin-bottom:10px">
                    <div style="font-weight:800"><?php esc_html_e( 'History', 'pocket-photo-editor' ); ?></div>
                </div>
                <div id="gallery" class="gallery"></div>
            </div>
        </div>
    </div>
    <!-- Bottom Prompt Bar -->
    <div class="bottomBar">
        <div class="bottomCard">
            <div class="promptRow">
                <textarea id="prompt" class="prompt" placeholder="Describe your edit… e.g., lighten skin; add warm glow; boost contrast"></textarea>
                <div id="generateBtn" class="pill"><div class="inner">Generate</div></div>
            </div>
            <div class="btnRow" style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap">
                <button id="rollbackBtn" class="btn">Roll Back to Selected</button>
            </div>
        </div>
    </div>
    <!-- Overlay for zoom -->
    <div id="overlay" class="overlay">
        <img id="overlayImg" src="" alt="Zoomed image">
    </div>
    <style>
    :root{
        /* Greyscale palette inspired by desktop software UI */
        --bg:#1d1d1f;
        --panel:#2a2b2e;
        --edge:#4f4f54;
        --text:#e0e0e0;
        --muted:#9d9fa3;
        --pill-gradient:linear-gradient(45deg, #4a4d52, #5e6066);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0;
        background:linear-gradient(180deg,var(--bg),#1f1f23 60%,var(--bg));
        color:var(--text);
        font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
    }
    .wrap{
        width:100%;
        margin:18px auto;
        padding:0 12px;
        display:grid;
        /* Main viewer takes approximately 9 parts to 1 part for the sidebar (90/10) */
        grid-template-columns:9fr 1fr;
        gap:18px;
    }
    /* Maintain two columns on all viewport widths */
    .ppe-card{
        background:linear-gradient(180deg, rgba(255,255,255,.025), rgba(255,255,255,.01));
        border:1px solid var(--edge);
        /* Sharper corners per request */
        border-radius:1px;
        box-shadow:0 8px 24px rgba(0,0,0,.5), inset 0 0 0 1px rgba(255,255,255,.03);
        padding:14px;
        /* Override admin default card dimensions */
        max-width:none;
        min-width:0;
    }
    .hRow{ display:flex; justify-content:space-between; align-items:center; gap:10px; }
    .help{font-size:12px; color:var(--muted);} 
    .viewer{
        border:1px solid var(--edge);
        border-radius:1px;
        background:var(--panel);
        position:relative;
        /* Increase the default height so the main image appears larger */
        min-height:600px;
        overflow:hidden;
        /* Ensure the viewer expands to fill remaining space next to the toolbar */
        flex: 1;
    }

    /* Container inside the viewer that hosts the ruler plugin. It stretches
       to fill the entire viewer. The plugin will inject its own markup
       (corner, rulers, stage) into this container. */
    .rulerContainer {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        overflow: hidden;
    }

    /* Rulers are provided by the jquery-ruler plugin; no custom ruler styles needed */
    /* Wrapper around the image that offsets for rulers */
    .imageWrapper{
        position:absolute;
        top:0;
        left:0;
        right:0;
        bottom:0;
        overflow:auto;
    }
    .imageWrapper img{
        display:block;
        transform-origin:top left;
    }
    .viewer img{ max-width:100%; max-height:100%; display:block; cursor:default; }
    .skeleton{
        position:relative;
        width:100%;
        height:100%;
        min-height:480px;
        background:#2d2d31;
    }
    .skeleton::after{
        content:"";
        position:absolute;
        inset:0;
        background:linear-gradient(110deg, rgba(255,255,255,.06) 8%, rgba(255,255,255,.18) 18%, rgba(255,255,255,.06) 33%);
        background-size:200% 100%;
        animation:shim 1.2s infinite;
    }
    @keyframes shim{0%{background-position:-200% 0}100%{background-position:200% 0}}
    .gallery{
        /* Display thumbnails vertically */
        display:flex;
        flex-direction:column;
        gap:10px;
    }
    .thumb{
        position:relative;
        border:1px solid var(--edge);
        border-radius:1px;
        overflow:hidden;
        background:var(--panel);
        cursor:pointer;
    }
    .thumb img{
        width:100%;
        height:90px;
        object-fit:cover;
        display:block;
    }
    .thumb .meta{
        position:absolute;
        left:6px;
        bottom:6px;
        background:rgba(0,0,0,.5);
        padding:2px 6px;
        border-radius:999px;
        font-size:10px;
        border:1px solid rgba(255,255,255,.1);
    }
    .btn{
        border:1px solid var(--edge);
        background:linear-gradient(180deg, #333438, #2b2c2f);
        color:var(--text);
        border-radius:1px;
        padding:10px 14px;
        font-weight:600;
        cursor:pointer;
        text-align:center;
    }
    .btn:hover{
        background:linear-gradient(180deg, #3f4045, #2f3034);
    }

    /* Small variant for zoom buttons */
    .btn-small{
        /* Square-ish buttons for vertical toolbar */
        padding:6px;
        font-size:16px;
        line-height:1;
        width:40px;
        height:40px;
        display:flex;
        align-items:center;
        justify-content:center;
    }
    /* Floating adjustment panel */
    .adjustPanel {
        position: absolute;
        top: 40px;
        right: 40px;
        width: 220px;
        background: var(--panel);
        border: 1px solid var(--edge);
        border-radius: 1px;
        padding: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,.6);
        z-index: 1000;
    }
    .adjustPanel .sliderRow {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 6px;
        font-size: 12px;
    }
    .adjustPanel .sliderRow label {
        flex: 1 0 70px;
    }
    .adjustPanel .sliderRow input[type=range] {
        flex: 2 1 auto;
        width: 100%;
    }

    /* Cursor styling for pan mode */
    .imageWrapper.pan-active {
        /* When pan mode is toggled on, the image wrapper should use a grab cursor. */
        cursor: grab;
    }
    .imageWrapper.pan-active.dragging {
        /* When dragging while in pan mode, show the grabbing cursor */
        cursor: grabbing;
    }

    /* Override the zoom-in cursor on the image when in pan mode. Without this the
       .viewer img style (cursor:zoom-in) takes precedence, preventing the grab
       cursor from showing. */
    .imageWrapper.pan-active img {
        cursor: grab !important;
    }
    .imageWrapper.pan-active.dragging img {
        cursor: grabbing !important;
    }

    /* Cursor styling for crop mode */
    .imageWrapper.crop-active {
        /* Use a crosshair cursor when drawing a crop rectangle */
        cursor: crosshair;
    }
    .imageWrapper.crop-active img {
        cursor: crosshair !important;
    }


    /* Crop overlay styles */
    .crop-overlay {
        position:absolute;
        inset:0;
        pointer-events:none;
        z-index:20;
    }
    .crop-rect {
        position:absolute;
        border:1px solid #00e0ff;
        box-shadow:0 0 0 9999px rgba(0,0,0,.4);
        pointer-events:none;
    }

    .pill{
        position:relative;
        border-radius:1px;
        padding:2px;
        background:var(--pill-gradient);
        transition:background .3s;
    }
    .pill::before{
        content:"";
        position:absolute;
        inset:2px;
        border-radius:1px;
        background:linear-gradient(180deg, #28292d, #202124);
    }
    .pill:hover{
        background:linear-gradient(45deg, #5e6066, #4a4d52);
    }
    .pill .inner{
        position:relative;
        display:flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        padding:8px 18px;
        border-radius:1px;
        color:#fff;
        font-weight:700;
        cursor:pointer;
    }
    @keyframes spin{to{filter:hue-rotate(360deg)}}
    .topButtons{
        /* Vertical toolbar style: remove absolute positioning and add border */
        display:flex;
        flex-direction:column;
        gap:8px;
        align-items:center;
        padding:8px;
        border:1px solid var(--edge);
        background:var(--panel);
        border-radius:1px;
        /* Prevent shrinking of toolbar when viewer resizes */
        flex-shrink:0;
        /* Separate toolbar from viewer */
        margin-right:8px;
    }
    .bottomBar{
        position:sticky;
        bottom:0;
        z-index:20;
        /* Use a solid background instead of a translucent gradient */
        background:var(--panel);
        padding:10px 0;
        border-top:1px solid rgba(255,255,255,.05);
    }
    .bottomCard{
        width:100%;
        margin:0 auto;
        padding:12px 14px;
        background:linear-gradient(180deg, rgba(255,255,255,.025), rgba(255,255,255,.01));
        border:1px solid var(--edge);
        border-radius:1px;
        box-shadow:0 8px 24px rgba(0,0,0,.5), inset 0 0 0 1px rgba(255,255,255,.03);
    }
    .promptRow{ display:flex; gap:10px; flex-wrap:wrap; }
    .prompt{
        flex:1;
        min-height:64px;
        padding:12px;
        border:1px solid var(--edge);
        border-radius:1px;
        background:var(--panel);
        color:var(--text);
        font:inherit;
        resize:vertical;
    }
    .overlay{ position:fixed; inset:0; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:100; cursor:zoom-out; }
    .overlay.open{display:flex;}
    .overlay img{ max-width:90vw; max-height:90vh; display:block; transition:transform .2s ease; }
    .error{ white-space:pre-wrap; color:#ff98a5; background:#2a1220; border:1px solid #5e1c2a; padding:10px; border-radius:1px; margin-top:8px; }
    /* Editor header styling */
    .editor-wrap h1 {
        color: var(--text);
        margin-bottom: 18px;
        font-size: 24px;
        font-weight: 700;
    }

    /* Highlight the Roll Back button when a version is selected */
    #rollbackBtn.active {
        background: var(--text);
        color: #1a233a;
    }

    /* Highlight the pan button when active */
    #panBtn.active {
        background: var(--text);
        color: #1a233a;
    }

    /* Provide extra space at the bottom of the editor so that the sticky
       bottom bar doesn't overlap the main content when the viewport height
       is short. This value should accommodate the height of the bottom bar
       and prompt row. */
    .editor-wrap {
        padding-bottom: 220px;
    }

    /* Center items vertically in the prompt row and adjust generate button size */
    .promptRow {
        align-items: center;
    }
    .pill .inner {
        padding: 6px 18px;
    }

    </style>
    <script>
    let SELECTED = null;
    // Current zoom level of the main viewer (1 = 100%)
    let VIEWER_SCALE = 1;
    // Whether pan mode is enabled for dragging the image
    let PANNING = false;
    // Whether crop mode is enabled
    let CROPPING = false;
    // Internal state for drawing crop rectangles
    let isDrawingCrop = false;
    let cropStartX = 0;
    let cropStartY = 0;
    let cropEndX = 0;
    let cropEndY = 0;
    // Internal state for drag operations
    let isDragging = false;
    let dragStartX, dragStartY, scrollStartX, scrollStartY;
    const viewer = document.getElementById('viewer');
    const gallery = document.getElementById('gallery');
    const errorBox = document.getElementById('errorBox');
    const uploadMsg= document.getElementById('uploadMsg');
    const downloadBtn = document.getElementById('downloadBtn');
    const topButtons = document.getElementById('topButtons');
    const overlay = document.getElementById('overlay');
    const overlayImg= document.getElementById('overlayImg');

    // Apply the current scale to the viewer image and update ruler spacing
    function applyScale(){
        const img = document.getElementById('canvasImg');
        if (img) {
            img.style.transform = 'scale(' + VIEWER_SCALE + ')';
        }
        updateRulers();
    }

    // Adjust tick spacing on the rulers based on current zoom
    /**
     * Recreate the rulers on the ruler container.  The vanilla ruler
     * implementation supports clearing and reinitialising a ruler on the
     * same container.  This function ensures that only one set of
     * horizontal and vertical rulers exist at any time.  It is called
     * whenever the image is loaded or when the zoom level changes.
     */
    function updateRulers(){
        const container = document.getElementById('rulerContainer');
        if (!container || typeof window.Ruler === 'undefined') return;
        // Clear any existing ruler instance
        window.Ruler.clear(container);
        // Recreate the ruler using our dark theme defaults.  Because
        // vanilla rulers use fixed tick spacing for pixel units, we do
        // not adjust the tick sizes based on VIEWER_SCALE; the crosshair
        // continues to track the cursor accurately regardless of zoom.
        window.Ruler.create(container, {
            vRuleSize: 18,
            hRuleSize: 18,
            // Disable crosshair lines; the built‑in crosshair interferes with
            // the editor’s cursor styling.  Leaving showCrosshair off
            // prevents a line from following the mouse across the image.
            showCrosshair: false,
            showMousePos: false,
            tickColor: '#4f4f54',
            crosshairColor: '#666',
            crosshairStyle: 'solid',
            unit: 'px',
            unitPrecision: 0
        });
    }
    function setError(msg){ errorBox.style.display='block'; errorBox.textContent=msg; }
    function clearError(){ errorBox.style.display='none'; errorBox.textContent=''; }
    function showSkeleton(){
        // Show a loading skeleton inside the image wrapper and hide controls
        const wrapper = document.getElementById('imageWrapper');
        if (wrapper) {
            wrapper.innerHTML = '<div class="skeleton"></div>';
        }
        const tb = document.getElementById('topButtons');
        if (tb) {
            tb.style.display = 'none';
        }
        // Hide adjustment panel when showing skeleton
        const adjP = document.getElementById('adjustPanel');
        if (adjP) {
            adjP.style.display = 'none';
        }
    }
    function setViewer(url){
        // Insert new image into the image wrapper and reset zoom
        const wrapper = document.getElementById('imageWrapper');
        if (wrapper) {
            // If crop mode is active, reset the crop overlay when replacing the image
            const overlayEl = wrapper.querySelector('.crop-overlay');
            if (overlayEl) {
                overlayEl.remove();
            }
            CROPPING = false;
            // Hide confirm/cancel buttons if present
            const ccBtn = document.getElementById('cropConfirmBtn');
            const cxBtn = document.getElementById('cropCancelBtn');
            if (ccBtn) ccBtn.style.display = 'none';
            if (cxBtn) cxBtn.style.display = 'none';
            wrapper.innerHTML = '<img id="canvasImg" src="'+url+'" alt="Image">';
        }
        // Reset viewer scale and apply
        VIEWER_SCALE = 1;
        applyScale();
        // Show control buttons
        const tb = document.getElementById('topButtons');
        if (tb) tb.style.display = 'flex';
        // Update download link
        const dlBtn = document.getElementById('downloadBtn');
        if (dlBtn) dlBtn.href = url;
        // Load base image for adjustments
        if (typeof loadBaseImage === 'function') {
            loadBaseImage(url);
        }
        // Recreate rulers whenever a new image is loaded.  Without this
        // call, the ruler may not align correctly with the viewer when
        // switching images.
        updateRulers();

        // Attach overlay zoom on click
        const img = document.getElementById('canvasImg');
        if (img) {
            img.addEventListener('click', (e) => {
                // If pan mode or crop mode is active, do not open overlay
                if (PANNING || CROPPING) {
                    e.preventDefault();
                    return;
                }
                overlayImg.src = url;
                overlay.classList.add('open');
                overlayImg.dataset.scale = '1';
                overlayImg.style.transform = 'scale(1)';
            });
        }
    }
    function updateGallery(items){
        gallery.innerHTML='';
        // Reset selection and deactivate rollback button on refresh
        SELECTED = null;
        const rb = document.getElementById('rollbackBtn');
        if (rb) rb.classList.remove('active');
        if(!items || !items.length){
            gallery.innerHTML = '<div class="help">No versions yet.</div>';
            // Hide adjustment panel when there are no versions
            const adjP = document.getElementById('adjustPanel');
            if (adjP) adjP.style.display = 'none';
            return;
        }
        items.forEach(v => {
            const a = document.createElement('a');
            a.className = 'thumb';
            a.href = 'javascript:void(0)';
            // Create image and meta
            a.innerHTML = '<img src="'+v.url+'" alt=""><div class="meta">'+v.type+'</div>';
            // Add click handler for selecting
            a.addEventListener('click', (e)=>{
                // Prevent deletion click from triggering selection
                if (e.target.closest('.trash')) return;
                setViewer(v.url);
                setSelected(a, v);
            });
            // If attachment exists and not original, add trash icon
            if (v.type !== 'original') {
                const trash = document.createElement('span');
                trash.className = 'trash';
                trash.title = 'Delete this version';
                trash.innerHTML = '🗑';
                trash.style.position = 'absolute';
                trash.style.top = '4px';
                trash.style.right = '4px';
                trash.style.fontSize = '12px';
                trash.style.cursor = 'pointer';
                trash.style.color = '#ff6970';
                trash.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    if (!confirm('You are about to delete this item from the media library. Continue?')) return;
                    const fd = new FormData();
                    fd.append('subaction','delete');
                    fd.append('path', v.path);
                    fd.append('attachment_id', v.attachment_id || '');
                    try {
                        const resp = await postForm(fd);
                        if (resp.ok) {
                            updateGallery(resp.all);
                            // If current base changed, update viewer
                            if (resp.current_base) {
                                const item = resp.all.find(x => x.path === resp.current_base);
                                if (item) {
                                    setViewer(item.url);
                                }
                            } else {
                                // No items left: clear viewer content but keep rulers
                                const wrapper = document.getElementById('imageWrapper');
                                if (wrapper) {
                                    wrapper.innerHTML = '<div class="help" style="padding:12px">No image selected</div>';
                                }
                                const tb = document.getElementById('topButtons');
                                if (tb) tb.style.display = 'none';
                                // Hide adjustment panel when no image remains
                                const adjP = document.getElementById('adjustPanel');
                                if (adjP) adjP.style.display = 'none';
                            }
                        } else {
                            setError(resp.error || 'Delete failed');
                        }
                    } catch(err) {
                        setError(err.message || String(err));
                    }
                });
                a.appendChild(trash);
            }
            gallery.appendChild(a);
        });
    }
    function setSelected(el, data){
        SELECTED = data;
        gallery.querySelectorAll('.thumb').forEach(t=> t.style.outline='none');
        if (el) el.style.outline='4px solid #ffffff';
        // Remove active state from rollback button until a rollback is performed
        const rb = document.getElementById('rollbackBtn');
        if (rb) rb.classList.remove('active');
    }
    async function postForm(formData){ formData.append('action','ppe_action'); formData.append('_ajax_nonce','<?php echo esc_js($nonce); ?>'); const res = await fetch('<?php echo esc_url_raw($ajax_url); ?>', { method:'POST', body: formData }); return await res.json(); }
    document.getElementById('selectImageBtn').addEventListener('click', () => {
        const frame = wp.media({ title: 'Select an image', multiple: false, library: { type: 'image' } });
        frame.on('select', async () => {
            const attachment = frame.state().get('selection').first().toJSON();
            clearError(); uploadMsg.textContent = 'Loading…';
            const fd = new FormData();
            fd.append('subaction','select');
            fd.append('attachment_id', attachment.id);
            try{
                const j = await postForm(fd);
                if (!j.ok){ setError(j.error || 'Select failed'); uploadMsg.textContent=''; return; }
                setViewer(j.version.url);
                updateGallery(j.all);
                uploadMsg.textContent = 'Loaded ✓';
                const first = gallery.querySelector('.thumb'); if (first) first.click();
            }catch(err){ setError(err.message||String(err)); uploadMsg.textContent=''; }
        });
        frame.open();
    });
    document.getElementById('generateBtn').addEventListener('click', async ()=>{
        const promptVal = document.getElementById('prompt').value.trim(); if (!promptVal){ setError('Enter a prompt'); return; }
        clearError(); showSkeleton(); const fd = new FormData(); fd.append('subaction','edit'); fd.append('prompt', promptVal);
        try{ const j = await postForm(fd); if (!j.ok){ setError(j.error || 'Edit failed'); return; }
            setViewer(j.version.url); updateGallery(j.all); const first = gallery.querySelector('.thumb'); if (first) first.click(); document.getElementById('prompt').value = ''; }catch(err){ setError(err.message||String(err)); }
    });
    document.getElementById('undoBtn').addEventListener('click', async ()=>{
        const fd = new FormData(); fd.append('subaction','undo'); try{ const j = await postForm(fd); if (j.ok){ const fd2 = new FormData(); fd2.append('subaction','list'); const k = await postForm(fd2); if (k.ok){ updateGallery(k.all); const target = k.all.find(v => v.path === j.current_base) || k.all[0]; if (target){ setViewer(target.url); } } } else { setError(j.error || 'Undo failed'); } }catch(err){ setError(err.message||String(err)); }
    });
    document.getElementById('rollbackBtn').addEventListener('click', async ()=>{
        if (!SELECTED){ setError('Tap a thumbnail first'); return; }
        const fd = new FormData();
        fd.append('subaction','rollback');
        fd.append('path', SELECTED.path);
        try{
            const j = await postForm(fd);
            if (j.ok){
                setViewer(SELECTED.url);
                // Highlight the rollback button to indicate the current image is a rolled back version
                const rb = document.getElementById('rollbackBtn');
                if (rb) rb.classList.add('active');
            } else {
                setError(j.error || 'Rollback failed');
            }
        }catch(err){
            setError(err.message||String(err));
        }
    });

    // Zoom controls for main viewer
    document.getElementById('zoomInBtn').addEventListener('click', () => {
        // Increase scale but cap at 5x
        VIEWER_SCALE = Math.min(5, VIEWER_SCALE + 0.1);
        applyScale();
    });
    document.getElementById('zoomOutBtn').addEventListener('click', () => {
        // Decrease scale but ensure not below 0.2x
        VIEWER_SCALE = Math.max(0.2, VIEWER_SCALE - 0.1);
        applyScale();
    });

    // Pan controls for dragging when zoomed
    const panBtn = document.getElementById('panBtn');
    const imageWrapper = document.getElementById('imageWrapper');
    if (panBtn && imageWrapper) {
        panBtn.addEventListener('click', () => {
            PANNING = !PANNING;
            panBtn.classList.toggle('active', PANNING);
            if (PANNING) {
                imageWrapper.classList.add('pan-active');
            } else {
                imageWrapper.classList.remove('pan-active');
            }
        });
        imageWrapper.addEventListener('mousedown', (e) => {
            if (!PANNING) return;
            isDragging = true;
            dragStartX = e.clientX;
            dragStartY = e.clientY;
            scrollStartX = imageWrapper.scrollLeft;
            scrollStartY = imageWrapper.scrollTop;
            imageWrapper.classList.add('dragging');
            e.preventDefault();
        });
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const dx = e.clientX - dragStartX;
            const dy = e.clientY - dragStartY;
            imageWrapper.scrollLeft = scrollStartX - dx;
            imageWrapper.scrollTop = scrollStartY - dy;
        });
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                imageWrapper.classList.remove('dragging');
            }
        });
    }

    // Crosshair handling is provided by jquery-ruler plugin; no manual crosshair update is needed.

    // Crop tool controls (custom implementation using overlay and canvas)
    const cropBtn = document.getElementById('cropBtn');
    const cropConfirmBtn = document.getElementById('cropConfirmBtn');
    const cropCancelBtn = document.getElementById('cropCancelBtn');

    function resetCropUI() {
        CROPPING = false;
        // Remove UI highlights
        if (cropBtn) cropBtn.classList.remove('active');
        if (cropConfirmBtn) cropConfirmBtn.style.display = 'none';
        if (cropCancelBtn) cropCancelBtn.style.display = 'none';
        // Remove crop overlay
        const overlayEl = imageWrapper.querySelector('.crop-overlay');
        if (overlayEl) overlayEl.remove();
        // Remove crosshair cursor from wrapper
        imageWrapper.classList.remove('crop-active');
        isDrawingCrop = false;
    }

    async function sendCropToServer(dataUrl, mime) {
        // Legacy helper retained for backward compatibility but no longer used.
        const fd = new FormData();
        fd.append('subaction','crop');
        const base64 = dataUrl.split(',')[1] || '';
        fd.append('image', base64);
        fd.append('mime', mime || 'image/png');
        const res = await postForm(fd);
        return res;
    }

    /**
     * Send a cropped image to the server by converting the canvas to a Blob and
     * uploading it as a file. This avoids issues with large base64 strings
     * that some servers reject. The PHP handler for `crop_upload` treats the
     * uploaded file similarly to a normal image upload but marks the version
     * as a crop.
     *
     * @param {HTMLCanvasElement} canvas The canvas containing the cropped area.
     * @returns {Promise<Object>} The JSON response from the server.
     */
    function sendCropToServerViaFile(canvas) {
        return new Promise((resolve, reject) => {
            canvas.toBlob(async (blob) => {
                if (!blob) {
                    reject(new Error('Failed to create blob from crop'));
                    return;
                }
                const fd = new FormData();
                fd.append('subaction','crop_upload');
                // Name the blob; WP uses file name to determine type
                fd.append('photo', blob, 'crop.png');
                try {
                    const res = await postForm(fd);
                    resolve(res);
                } catch (err) {
                    reject(err);
                }
            }, 'image/png');
        });
    }

    if (cropBtn && imageWrapper) {
        cropBtn.addEventListener('click', () => {
            CROPPING = !CROPPING;
            if (CROPPING) {
                // Disable panning if active
                if (PANNING) {
                    PANNING = false;
                    panBtn.classList.remove('active');
                    imageWrapper.classList.remove('pan-active');
                }
                // Set crop-active class to update cursor
                imageWrapper.classList.add('crop-active');
                // Show confirm/cancel buttons and highlight crop tool
                cropConfirmBtn.style.display = 'flex';
                cropCancelBtn.style.display = 'flex';
                cropBtn.classList.add('active');
                // Ensure overlay exists for drawing the crop rectangle
                let overlayEl = imageWrapper.querySelector('.crop-overlay');
                if (!overlayEl) {
                    overlayEl = document.createElement('div');
                    overlayEl.className = 'crop-overlay';
                    imageWrapper.appendChild(overlayEl);
                }
            } else {
                resetCropUI();
            }
        });

        // Draw crop rectangle by mouse drag
        imageWrapper.addEventListener('mousedown', e => {
            if (!CROPPING) return;
            const wrapperRect = imageWrapper.getBoundingClientRect();
            isDrawingCrop = true;
            cropStartX = e.clientX - wrapperRect.left + imageWrapper.scrollLeft;
            cropStartY = e.clientY - wrapperRect.top + imageWrapper.scrollTop;
            let overlayEl = imageWrapper.querySelector('.crop-overlay');
            if (!overlayEl) {
                overlayEl = document.createElement('div');
                overlayEl.className = 'crop-overlay';
                imageWrapper.appendChild(overlayEl);
            }
            let rectEl = overlayEl.querySelector('.crop-rect');
            if (!rectEl) {
                rectEl = document.createElement('div');
                rectEl.className = 'crop-rect';
                overlayEl.appendChild(rectEl);
            }
            rectEl.style.left = cropStartX + 'px';
            rectEl.style.top = cropStartY + 'px';
            rectEl.style.width = '0px';
            rectEl.style.height = '0px';
            e.preventDefault();
        });

        document.addEventListener('mousemove', e => {
            if (!isDrawingCrop) return;
            const wrapperRect = imageWrapper.getBoundingClientRect();
            cropEndX = e.clientX - wrapperRect.left + imageWrapper.scrollLeft;
            cropEndY = e.clientY - wrapperRect.top + imageWrapper.scrollTop;
            const x = Math.min(cropStartX, cropEndX);
            const y = Math.min(cropStartY, cropEndY);
            const w = Math.abs(cropEndX - cropStartX);
            const h = Math.abs(cropEndY - cropStartY);
            const overlayEl = imageWrapper.querySelector('.crop-overlay');
            if (!overlayEl) return;
            let rectEl = overlayEl.querySelector('.crop-rect');
            if (!rectEl) {
                rectEl = document.createElement('div');
                rectEl.className = 'crop-rect';
                overlayEl.appendChild(rectEl);
            }
            rectEl.style.left = x + 'px';
            rectEl.style.top = y + 'px';
            rectEl.style.width = w + 'px';
            rectEl.style.height = h + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isDrawingCrop) {
                isDrawingCrop = false;
            }
        });
    }

    if (cropConfirmBtn && imageWrapper) {
        cropConfirmBtn.addEventListener('click', async () => {
            if (!CROPPING) return;
            const img = document.getElementById('canvasImg');
            if (!img) {
                setError('No image to crop');
                return;
            }
            const overlayEl = imageWrapper.querySelector('.crop-overlay');
            const rectEl = overlayEl ? overlayEl.querySelector('.crop-rect') : null;
            if (!rectEl) {
                setError('Draw a crop area first');
                return;
            }
            // Calculate crop rectangle relative to the natural image size
            const imgRect = img.getBoundingClientRect();
            const wrapRect = imageWrapper.getBoundingClientRect();
            const scaleX = img.naturalWidth / imgRect.width;
            const scaleY = img.naturalHeight / imgRect.height;
            const rx = parseFloat(rectEl.style.left) - imageWrapper.scrollLeft;
            const ry = parseFloat(rectEl.style.top) - imageWrapper.scrollTop;
            const rw = parseFloat(rectEl.style.width);
            const rh = parseFloat(rectEl.style.height);
            const x = Math.max(0, Math.floor((rx + wrapRect.left - imgRect.left) * scaleX));
            const y = Math.max(0, Math.floor((ry + wrapRect.top - imgRect.top) * scaleY));
            const w = Math.max(1, Math.floor(rw * scaleX));
            const h = Math.max(1, Math.floor(rh * scaleY));
            // Draw cropped area into canvas
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, x, y, w, h, 0, 0, w, h);
            try {
                const resp = await sendCropToServerViaFile(canvas);
                if (!resp.ok) {
                    setError(resp.error || 'Crop failed');
                    return;
                }
                setViewer(resp.version.url);
                updateGallery(resp.all);
                resetCropUI();
            } catch(err) {
                setError(err.message || String(err));
            }
        });
    }

    if (cropCancelBtn && imageWrapper) {
        cropCancelBtn.addEventListener('click', () => {
            resetCropUI();
        });
    }

    // ======== Adjustment sliders ========
    // Offscreen canvas for processing adjustments
    let baseImageObj = null;
    let baseImageData = null;
    const adjCanvas = document.createElement('canvas');
    const adjCtx = adjCanvas.getContext('2d');
    // References to UI elements
    const adjustPanel = document.getElementById('adjustPanel');
    const expSlider = document.getElementById('expSlider');
    const contrastSlider = document.getElementById('contrastSlider');
    const highlightSlider = document.getElementById('highlightSlider');
    const shadowSlider = document.getElementById('shadowSlider');
    const whitesSlider = document.getElementById('whitesSlider');
    const blacksSlider = document.getElementById('blacksSlider');
    const resetAdjustBtn = document.getElementById('resetAdjustBtn');
    const applyAdjustBtn = document.getElementById('applyAdjustBtn');
    // Load an image into offscreen canvas and store its pixels
    function loadBaseImage(url) {
        baseImageObj = new Image();
        baseImageObj.crossOrigin = '';
        baseImageObj.onload = () => {
            adjCanvas.width = baseImageObj.naturalWidth || baseImageObj.width;
            adjCanvas.height = baseImageObj.naturalHeight || baseImageObj.height;
            adjCtx.clearRect(0, 0, adjCanvas.width, adjCanvas.height);
            adjCtx.drawImage(baseImageObj, 0, 0);
            baseImageData = adjCtx.getImageData(0, 0, adjCanvas.width, adjCanvas.height);
            // Reset sliders to defaults when new image loaded
            [expSlider, contrastSlider, highlightSlider, shadowSlider, whitesSlider, blacksSlider].forEach(sl => {
                if (sl) sl.value = 0;
            });
            updateAdjustmentPreview();
            // Show the panel now that an image is loaded
            if (adjustPanel) adjustPanel.style.display = 'block';
        };
        baseImageObj.src = url;
    }
    // Compute adjustment preview and update displayed image and download link
    function updateAdjustmentPreview() {
        if (!baseImageData) return;
        const data = new Uint8ClampedArray(baseImageData.data);
        const width = adjCanvas.width;
        const height = adjCanvas.height;
        const expVal = parseInt(expSlider && expSlider.value ? expSlider.value : '0', 10);
        const contrastVal = parseInt(contrastSlider && contrastSlider.value ? contrastSlider.value : '0', 10);
        const highlightVal = parseInt(highlightSlider && highlightSlider.value ? highlightSlider.value : '0', 10);
        const shadowVal = parseInt(shadowSlider && shadowSlider.value ? shadowSlider.value : '0', 10);
        const whitesVal = parseInt(whitesSlider && whitesSlider.value ? whitesSlider.value : '0', 10);
        const blacksVal = parseInt(blacksSlider && blacksSlider.value ? blacksSlider.value : '0', 10);
        const exp = expVal * 2.55;
        const c = (contrastVal / 100) + 1;
        const intercept = 128 * (1 - c);
        for (let i = 0; i < data.length; i += 4) {
            for (let j = 0; j < 3; j++) {
                let v = baseImageData.data[i + j];
                // Exposure
                v += exp;
                // Contrast
                v = v * c + intercept;
                // Highlights
                if (v > 180) v += highlightVal * ((v - 180) / 75);
                // Shadows
                if (v < 75) v += shadowVal * ((75 - v) / 75);
                // Whites
                if (v > 200) v += whitesVal;
                // Blacks
                if (v < 50) v += blacksVal;
                data[i + j] = Math.max(0, Math.min(255, v));
            }
            // Keep alpha
        }
        const imgData = new ImageData(data, width, height);
        adjCtx.putImageData(imgData, 0, 0);
        const anyAdj = expVal || contrastVal || highlightVal || shadowVal || whitesVal || blacksVal;
        const previewUrl = anyAdj ? adjCanvas.toDataURL('image/png') : (baseImageObj ? baseImageObj.src : '');
        const imgEl = document.getElementById('canvasImg');
        if (imgEl && previewUrl) {
            imgEl.src = previewUrl;
        }
        if (downloadBtn && previewUrl) {
            downloadBtn.href = previewUrl;
        }
    }
    function resetAdjustments() {
        [expSlider, contrastSlider, highlightSlider, shadowSlider, whitesSlider, blacksSlider].forEach(sl => { if (sl) sl.value = 0; });
        updateAdjustmentPreview();
    }
    async function applyAdjustments() {
        if (!baseImageData) return;
        const values = [expSlider, contrastSlider, highlightSlider, shadowSlider, whitesSlider, blacksSlider].map(sl => parseInt(sl && sl.value ? sl.value : '0', 10));
        const anyAdj = values.some(v => v !== 0);
        if (!anyAdj) return;
        // Create a blob from the current preview canvas
        await new Promise((resolve, reject) => {
            adjCanvas.toBlob(async (blob) => {
                if (!blob) { reject(new Error('Failed to create blob')); return; }
                const fd = new FormData();
                fd.append('photo', blob, 'adjust.png');
                fd.append('subaction','adjust_upload');
                try {
                    const resp = await postForm(fd);
                    if (resp && resp.ok) {
                        setViewer(resp.version.url);
                        updateGallery(resp.all);
                        // base image replaced with new version
                        loadBaseImage(resp.version.url);
                    } else {
                        setError(resp.error || 'Adjust upload failed');
                    }
                    resolve();
                } catch(err) {
                    setError(err.message || String(err));
                    reject(err);
                }
            }, 'image/png');
        });
    }
    // Attach events
    [expSlider, contrastSlider, highlightSlider, shadowSlider, whitesSlider, blacksSlider].forEach(sl => {
        if (sl) {
            sl.addEventListener('input', updateAdjustmentPreview);
        }
    });
    if (resetAdjustBtn) resetAdjustBtn.addEventListener('click', resetAdjustments);
    if (applyAdjustBtn) applyAdjustBtn.addEventListener('click', applyAdjustments);
    // Drag functionality for the adjust panel
    if (adjustPanel) {
        const header = adjustPanel.querySelector('.adjustHeader');
        let isDraggingPanel = false;
        let startX = 0, startY = 0;
        let initLeft = 0, initTop = 0;
        function movePanel(e) {
            if (!isDraggingPanel) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            adjustPanel.style.left = (initLeft + dx) + 'px';
            adjustPanel.style.top = (initTop + dy) + 'px';
        }
        function stopDragging() {
            isDraggingPanel = false;
            document.removeEventListener('mousemove', movePanel);
            document.removeEventListener('mouseup', stopDragging);
        }
        if (header) {
            header.addEventListener('mousedown', (e) => {
                isDraggingPanel = true;
                startX = e.clientX;
                startY = e.clientY;
                const rect = adjustPanel.getBoundingClientRect();
                initLeft = rect.left;
                initTop = rect.top;
                document.addEventListener('mousemove', movePanel);
                document.addEventListener('mouseup', stopDragging);
            });
        }
    }
    overlay.addEventListener('click', () => { overlay.classList.remove('open'); });
    overlayImg.addEventListener('wheel', (e) => { e.preventDefault(); const delta = Math.sign(e.deltaY); let scale = parseFloat(overlayImg.dataset.scale || '1'); if (delta < 0) scale += 0.1; else scale -= 0.1; scale = Math.max(0.5, Math.min(5, scale)); overlayImg.dataset.scale = scale; overlayImg.style.transform = 'scale(' + scale + ')'; });
    (async function init(){
        // Initialize rulers on page load
        updateRulers();
        try{
            const fd = new FormData();
            fd.append('subaction','list');
            const j = await postForm(fd);
            if (j.ok){
                updateGallery(j.all);
                if (j.current_base){
                    const target = j.all.find(v => v.path === j.current_base) || j.all[0];
                    if (target){ setViewer(target.url); }
                }
            }
        } catch(_){ /* ignore */ }
    })();

    // No jQuery ruler initialisation is needed.  Our custom vanilla
    // implementation is initialised in updateRulers().

    // If an initial attachment ID is provided from query string, auto-select it
    (function(){
        const initialAttachment = <?php echo json_encode($initial_attachment_id); ?>;
        if (initialAttachment && Number(initialAttachment) > 0) {
            clearError(); uploadMsg.textContent = 'Loading…';
            const fd = new FormData();
            fd.append('subaction','select');
            fd.append('attachment_id', initialAttachment);
            postForm(fd).then(j => {
                if (j && j.ok){ setViewer(j.version.url); updateGallery(j.all); uploadMsg.textContent = 'Loaded ✓'; const first = gallery.querySelector('.thumb'); if (first) first.click(); }
                else { setError(j.error || 'Select failed'); uploadMsg.textContent=''; }
            }).catch(err => { setError(err.message||String(err)); uploadMsg.textContent=''; });
        }
    })();
    </script>
    <?php
}

/**
 * Handle AJAX requests for the photo editor. The `subaction` POST field
 * determines what operation to perform. Responses mirror the original
 * standalone script with JSON structures containing versions and current
 * base info. Images are stored in `wp-content/uploads/ppe_software/<session>`
 * and inserted into the WordPress media library.
 */
function ppe_handle_ajax() {
    check_ajax_referer('ppe_nonce');
    // Ensure session exists
    ppe_start_session();
    $sid = $_SESSION['ppe_session_id'] ?? '';
    if (!$sid) {
        wp_send_json(['ok' => 0, 'error' => 'Invalid session']);
    }
    // Setup session directory
    $uploads = wp_upload_dir();
    $base_dir = trailingslashit($uploads['basedir']) . 'ppe_software/' . $sid;
    $base_url = trailingslashit($uploads['baseurl']) . 'ppe_software/' . $sid;
    if (!file_exists($base_dir)) {
        wp_mkdir_p($base_dir);
    }
    $db_path = $base_dir . '/db.json';
    // Helper: read DB
    $db = [
        'versions' => [],
        'current_base_path' => null,
        'original_path' => null,
        'original_name' => null,
    ];
    if (file_exists($db_path)) {
        $json = file_get_contents($db_path);
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            $db = array_merge($db, $arr);
        }
    }
    $subaction = $_POST['subaction'] ?? '';
    // Save DB helper
    $save_db = function() use ($db_path, &$db) {
        $fp = fopen($db_path, 'c+');
        if ($fp) {
            flock($fp, LOCK_EX);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    };
    // Generate a safe filename
    $safe_name = function($prefix, $ext) {
        return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    };
    $clean_original_name = function($name) {
        $name = sanitize_file_name($name);
        if ($name === '') {
            return 'image';
        }
        return $name;
    };
    $build_edit_name = function($original_name, $ext) use ($clean_original_name) {
        $timestamp = current_time('Ymd-His');
        return $clean_original_name($original_name) . '-edit-' . $timestamp . '.' . $ext;
    };
    // Determine extension from MIME
    $ext_for_mime = function($mime) {
        switch ($mime) {
            case 'image/jpeg': return 'jpg';
            case 'image/png': return 'png';
            case 'image/webp': return 'webp';
            default: return 'png';
        }
    };
    // Convert to PNG if Imagick is available for unsupported MIME
    $convert_to_png = function($src) {
        $out = preg_replace('/\.[A-Za-z0-9]+$/', '', $src) . '.png';
        if (class_exists('Imagick')) {
            try {
                $im = new Imagick($src);
                $im->setImageFormat('png');
                if ($im->getImageAlphaChannel()) {
                    $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                }
                $im->writeImage($out);
                $im->destroy();
                return $out;
            } catch (Throwable $e) {
                copy($src, $out);
                return $out;
            }
        }
        copy($src, $out);
        return $out;
    };
    // Save base64 image to disk
    $save_b64 = function($dir, $b64, $mime, $prefix='img') use ($safe_name, $ext_for_mime) {
        $ext = $ext_for_mime($mime);
        $path = trailingslashit($dir) . $safe_name($prefix, $ext);
        file_put_contents($path, base64_decode($b64));
        return $path;
    };
    $save_b64_named = function($dir, $b64, $mime, $filename) {
        $path = trailingslashit($dir) . $filename;
        file_put_contents($path, base64_decode($b64));
        return $path;
    };
    // Insert file into media library and return attachment info
    $insert_media = function($file_path, $mime) {
        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment = [
            'post_mime_type' => $filetype['type'] ?? $mime,
            'post_title'     => sanitize_file_name(basename($file_path)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $file_path);
        if (!is_wp_error($attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);
            $url = wp_get_attachment_url($attach_id);
            return [$attach_id, $url];
        }
        return [null, null];
    };
    // Gemini API call function
    $call_api = function($prompt, $basePath) {
        // Fetch API key from plugin settings; fall back to environment variable
        $api_key = get_option('ppe_api_key', '');
        if (!$api_key) {
            $api_key = getenv('GEMINI_API_KEY') ?: '';
        }
        if (!$api_key) return [false, 'Missing API key', null];
        $model = trim(get_option('ppe_api_model', 'gemini-3-pro-image-preview'));
        if ($model === '') {
            $model = 'gemini-3-pro-image-preview';
        }
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent";
        $parts = [];
        if ($prompt !== '') $parts[] = ['text' => $prompt];
        if ($basePath && file_exists($basePath)) {
            $mime = mime_content_type($basePath) ?: 'image/png';
            $bytes = file_get_contents($basePath);
            if ($bytes !== false) {
                $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
            }
        }
        $body = ['contents' => [ ['parts' => $parts] ]];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint . '?key=' . urlencode($api_key),
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 180,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) return [false, "cURL error: $err", null];
        if ($code !== 200) return [false, "HTTP $code\n$res", null];
        return [true, null, $res];
    };
    // Parse image from API response
    $parse_image = function($json) {
        $j = json_decode($json, true);
        $parts = $j['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $p) {
            if (isset($p['inlineData']['data'])) {
                return [$p['inlineData']['data'], $p['inlineData']['mimeType'] ?? 'image/png', $j['usageMetadata'] ?? null];
            }
            if (isset($p['inline_data']['data'])) {
                return [$p['inline_data']['data'], $p['inline_data']['mime_type'] ?? 'image/png', $j['usage_metadata'] ?? null];
            }
        }
        return [null, null, null];
    };
    // Handle each subaction
    if ($subaction === 'select') {
        $attach_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attach_id) {
            wp_send_json(['ok'=>0, 'error'=>'Invalid attachment']);
        }
        $orig_path = get_attached_file($attach_id);
        if (!$orig_path || !file_exists($orig_path)) {
            wp_send_json(['ok'=>0, 'error'=>'File not found']);
        }
        $mime = mime_content_type($orig_path) ?: 'image/png';
        $ext = $ext_for_mime($mime);
        $dest_path = trailingslashit($base_dir) . $safe_name('orig', $ext);
        $db['original_name'] = $clean_original_name(pathinfo($orig_path, PATHINFO_FILENAME));
        // Copy the selected image into our session folder
        copy($orig_path, $dest_path);
        // Compute accessible URL for the copy
        $dest_url = trailingslashit($base_url) . basename($dest_path);
        // Reset versions for a new image selection
        $db['versions'] = [];
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'original',
            'path' => $dest_path,
            'url' => $dest_url,
            'prompt' => null,
            'base' => null,
            'usage' => null
        ];
        array_unshift($db['versions'], $ver);
        $db['original_path'] = $dest_path;
        $db['current_base_path'] = $dest_path;
        $save_db();
        wp_send_json(['ok'=>1, 'version'=>$ver, 'all'=>$db['versions'], 'current_base'=>$db['current_base_path']]);
    }
    if ($subaction === 'upload') {
        if (empty($_FILES['photo'])) {
            wp_send_json(['ok' => 0, 'error' => 'No file']);
        }
        $file = $_FILES['photo'];
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $map = [1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Upload interrupted',4=>'No file uploaded',6=>'Missing temp folder',7=>'Failed to write',8=>'Blocked by extension'];
            $msg = $map[$err] ?? ('Upload error '.$err);
            wp_send_json(['ok'=>0, 'error' => $msg]);
        }
        $tmp = $file['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $supported = ['image/jpeg','image/png','image/webp'];
        $db['original_name'] = $clean_original_name(pathinfo($file['name'] ?? 'image', PATHINFO_FILENAME));
        if (!in_array($mime, $supported, true)) {
            // Save original binary then convert to PNG
            $orig_name = $safe_name('orig', 'bin');
            $orig_path = trailingslashit($base_dir) . $orig_name;
            move_uploaded_file($tmp, $orig_path);
            $png_path = $convert_to_png($orig_path);
            $saved_path = $png_path;
            $mime = 'image/png';
        } else {
            $ext = $ext_for_mime($mime);
            $saved_path = trailingslashit($base_dir) . $safe_name('orig', $ext);
            move_uploaded_file($tmp, $saved_path);
        }
        // Insert into media library
        [$attach_id, $url] = $insert_media($saved_path, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'original',
            'path' => $saved_path,
            'url' => $url ?: trailingslashit($base_url) . basename($saved_path),
            'attachment_id' => $attach_id,
            'prompt' => null,
            'base' => null,
            'usage' => null
        ];
        array_unshift($db['versions'], $ver);
        $db['original_path'] = $saved_path;
        $db['current_base_path'] = $saved_path;
        $save_db();
        wp_send_json(['ok' => 1, 'version' => $ver, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]);
    }
    if ($subaction === 'edit') {
        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt === '') {
            wp_send_json(['ok'=>0, 'error'=>'Enter a prompt']);
        }
        $base = $db['current_base_path'] ?? null;
        if (!$base || !file_exists($base)) {
            wp_send_json(['ok'=>0, 'error'=>'Upload an image first']);
        }
        [$ok, $err, $resp] = $call_api($prompt, $base);
        if (!$ok) {
            wp_send_json(['ok'=>0, 'error'=>$err]);
        }
        [$b64, $mime, $usage] = $parse_image($resp);
        if (!$b64) {
            wp_send_json(['ok'=>0, 'error'=>'No image returned', 'raw'=>$resp]);
        }
        $ext = $ext_for_mime($mime);
        $original_name = $db['original_name'] ?? pathinfo($db['original_path'] ?? $base, PATHINFO_FILENAME);
        $filename = $build_edit_name($original_name, $ext);
        $out = $save_b64_named($base_dir, $b64, $mime, $filename);
        // Insert into media library
        [$attach_id, $url] = $insert_media($out, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'edit',
            'path' => $out,
            'url' => $url ?: trailingslashit($base_url) . basename($out),
            'attachment_id' => $attach_id,
            'prompt' => $prompt,
            'base' => $base,
            'usage' => $usage
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $out;
        $save_db();
        wp_send_json(['ok'=>1, 'version'=>$ver, 'all'=>$db['versions'], 'current_base'=>$db['current_base_path']]);
    }
    if ($subaction === 'undo') {
        $cur = $db['current_base_path'] ?? null;
        if (!$cur) {
            wp_send_json(['ok'=>0, 'error'=>'Nothing to undo']);
        }
        $prev = null;
        foreach ($db['versions'] as $v) {
            if (($v['path'] ?? '') === $cur) {
                $prev = $v['base'] ?? null;
                break;
            }
        }
        if ($prev && file_exists($prev)) {
            $db['current_base_path'] = $prev;
            $save_db();
            wp_send_json(['ok'=>1, 'current_base'=>$prev]);
        } else {
            wp_send_json(['ok'=>0, 'error'=>'Already at original']);
        }
    }

    // Crop action: save a cropped base64 image as a new version
    if ($subaction === 'crop') {
        $b64 = $_POST['image'] ?? '';
        $mime = $_POST['mime'] ?? 'image/png';
        if (!$b64) {
            wp_send_json(['ok' => 0, 'error' => 'Missing cropped image data']);
        }
        // Decode and save the cropped image
        $out = $save_b64($base_dir, $b64, $mime, 'crop');
        // Insert into media library
        list($attach_id, $url) = $insert_media($out, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'crop',
            'path' => $out,
            'url' => $url,
            'prompt' => null,
            'base' => $db['current_base_path'],
            'attachment_id' => $attach_id,
            'usage' => null
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $out;
        $save_db();
        wp_send_json(['ok' => 1, 'version' => $ver, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]);
    }

    // Custom crop action used by the JavaScript overlay cropper. It functions the
    // same as the legacy `crop` subaction but expects the image data to be
    // provided without a data URI prefix. This allows for smaller payloads.
    if ($subaction === 'crop_custom') {
        $b64 = $_POST['image'] ?? '';
        $mime = $_POST['mime'] ?? 'image/png';
        if (!$b64) {
            wp_send_json(['ok' => 0, 'error' => 'Missing cropped image data']);
        }
        // Decode the raw base64 string and save it to disk
        $out = $save_b64($base_dir, $b64, $mime, 'crop');
        // Insert the file into the media library
        list($attach_id, $url) = $insert_media($out, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'crop',
            'path' => $out,
            'url' => $url ?: trailingslashit($base_url) . basename($out),
            'prompt' => null,
            'base' => $db['current_base_path'],
            'attachment_id' => $attach_id,
            'usage' => null,
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $out;
        $save_db();
        wp_send_json([
            'ok' => 1,
            'version' => $ver,
            'all' => $db['versions'],
            'current_base' => $db['current_base_path'],
        ]);
    }
    // Crop upload: handle cropped image uploaded as a file (Blob). This is used
    // by the custom cropper to avoid sending large base64 strings. The logic
    // mirrors the upload handler but sets the version type to 'crop' and uses
    // the current base as its base reference.
    if ($subaction === 'crop_upload') {
        if (empty($_FILES['photo'])) {
            wp_send_json(['ok' => 0, 'error' => 'No file']);
        }
        $file = $_FILES['photo'];
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $map = [1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Upload interrupted',4=>'No file uploaded',6=>'Missing temp folder',7=>'Failed to write',8=>'Blocked by extension'];
            $msg = $map[$err] ?? ('Upload error '.$err);
            wp_send_json(['ok'=>0, 'error' => $msg]);
        }
        $tmp = $file['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $supported = ['image/jpeg','image/png','image/webp'];
        if (!in_array($mime, $supported, true)) {
            // Save raw binary then convert to PNG
            $orig_name = $safe_name('crop', 'bin');
            $orig_path = trailingslashit($base_dir) . $orig_name;
            move_uploaded_file($tmp, $orig_path);
            $png_path = $convert_to_png($orig_path);
            $saved_path = $png_path;
            $mime = 'image/png';
        } else {
            $ext = $ext_for_mime($mime);
            $saved_path = trailingslashit($base_dir) . $safe_name('crop', $ext);
            move_uploaded_file($tmp, $saved_path);
        }
        // Insert into media library
        [$attach_id, $url] = $insert_media($saved_path, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'crop',
            'path' => $saved_path,
            'url' => $url ?: trailingslashit($base_url) . basename($saved_path),
            'attachment_id' => $attach_id,
            'prompt' => null,
            'base' => $db['current_base_path'],
            'usage' => null
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $saved_path;
        $save_db();
        wp_send_json(['ok'=>1, 'version'=>$ver, 'all'=>$db['versions'], 'current_base'=>$db['current_base_path']]);
    }

    // Adjust upload: handle slider‑adjusted image uploaded as a file (Blob).
    // This mirrors the crop_upload handler but labels the version as 'adjust'.
    if ($subaction === 'adjust_upload') {
        if (empty($_FILES['photo'])) {
            wp_send_json(['ok' => 0, 'error' => 'No file']);
        }
        $file = $_FILES['photo'];
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $map = [1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Upload interrupted',4=>'No file uploaded',6=>'Missing temp folder',7=>'Failed to write',8=>'Blocked by extension'];
            $msg = $map[$err] ?? ('Upload error '.$err);
            wp_send_json(['ok'=>0, 'error' => $msg]);
        }
        $tmp = $file['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $supported = ['image/jpeg','image/png','image/webp'];
        if (!in_array($mime, $supported, true)) {
            // Save raw binary then convert to PNG
            $orig_name = $safe_name('adjust', 'bin');
            $orig_path = trailingslashit($base_dir) . $orig_name;
            move_uploaded_file($tmp, $orig_path);
            $png_path = $convert_to_png($orig_path);
            $saved_path = $png_path;
            $mime = 'image/png';
        } else {
            $ext = $ext_for_mime($mime);
            $saved_path = trailingslashit($base_dir) . $safe_name('adjust', $ext);
            move_uploaded_file($tmp, $saved_path);
        }
        // Insert into media library
        [$attach_id, $url] = $insert_media($saved_path, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'adjust',
            'path' => $saved_path,
            'url' => $url ?: trailingslashit($base_url) . basename($saved_path),
            'attachment_id' => $attach_id,
            'prompt' => null,
            'base' => $db['current_base_path'],
            'usage' => null
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $saved_path;
        $save_db();
        wp_send_json([
            'ok' => 1,
            'version' => $ver,
            'all' => $db['versions'],
            'current_base' => $db['current_base_path'],
        ]);
    }
    if ($subaction === 'rollback') {
        $path = $_POST['path'] ?? '';
        if (!$path || !file_exists($path)) {
            wp_send_json(['ok'=>0, 'error'=>'Invalid version']);
        }
        $db['current_base_path'] = $path;
        $save_db();
        wp_send_json(['ok'=>1, 'current_base'=>$path]);
    }

    // Delete action: permanently remove a version and its attachment
    if ($subaction === 'delete') {
        $path = $_POST['path'] ?? '';
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$path) {
            wp_send_json(['ok'=>0, 'error'=>'Invalid version']);
        }
        // Find index of version
        $index = null;
        foreach ($db['versions'] as $idx => $v) {
            if (($v['path'] ?? '') === $path) {
                $index = $idx;
                break;
            }
        }
        if ($index === null) {
            wp_send_json(['ok'=>0, 'error'=>'Version not found']);
        }
        // Delete attachment from media library if ID provided
        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
        // Remove the file from disk if it exists
        if (file_exists($path)) {
            @unlink($path);
        }
        // Remove version from list
        array_splice($db['versions'], $index, 1);
        // Adjust current base if necessary
        if (($db['current_base_path'] ?? '') === $path) {
            if (!empty($db['versions'])) {
                $db['current_base_path'] = $db['versions'][0]['path'];
            } else {
                $db['current_base_path'] = null;
            }
        }
        $save_db();
        wp_send_json(['ok'=>1, 'all'=>$db['versions'], 'current_base'=>$db['current_base_path']]);
    }
    // Crop file action: save an uploaded image (from cropping) as a new version.
    if ($subaction === 'crop_file') {
        if (empty($_FILES['photo'])) {
            wp_send_json(['ok' => 0, 'error' => 'No file for crop']);
        }
        $file = $_FILES['photo'];
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $map = [1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Upload interrupted',4=>'No file uploaded',6=>'Missing temp folder',7=>'Failed to write',8=>'Blocked by extension'];
            $msg = $map[$err] ?? ('Upload error '.$err);
            wp_send_json(['ok'=>0, 'error' => $msg]);
        }
        $tmp = $file['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $supported = ['image/jpeg','image/png','image/webp'];
        if (!in_array($mime, $supported, true)) {
            // Convert unsupported formats to PNG
            $orig_name = $safe_name('crop_orig', 'bin');
            $orig_path = trailingslashit($base_dir) . $orig_name;
            move_uploaded_file($tmp, $orig_path);
            $png_path = $convert_to_png($orig_path);
            $saved_path = $png_path;
            $mime = 'image/png';
        } else {
            $ext = $ext_for_mime($mime);
            $saved_path = trailingslashit($base_dir) . $safe_name('crop', $ext);
            move_uploaded_file($tmp, $saved_path);
        }
        // Insert into media library as a crop version
        list($attach_id, $url) = $insert_media($saved_path, $mime);
        $ver = [
            'id' => uniqid('ver_', true),
            'timestamp' => current_time('c'),
            'type' => 'crop',
            'path' => $saved_path,
            'url' => $url ?: trailingslashit($base_url) . basename($saved_path),
            'attachment_id' => $attach_id,
            'prompt' => null,
            'base' => $db['current_base_path'],
            'usage' => null
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $saved_path;
        $save_db();
        wp_send_json(['ok' => 1, 'version' => $ver, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]);
    }
    if ($subaction === 'list') {
        wp_send_json(['ok'=>1, 'all'=>$db['versions'], 'current_base'=>$db['current_base_path']]);
    }
    wp_send_json(['ok'=>0, 'error'=>'Unknown action']);
}
add_action('wp_ajax_ppe_action', 'ppe_handle_ajax');
add_action('wp_ajax_nopriv_ppe_action', 'ppe_handle_ajax');
