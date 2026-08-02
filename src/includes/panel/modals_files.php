<?php
/** File and collection operations panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Advanced file filters (pt 9). Sections are rendered from the permissions the session
     actually holds, so a group with only some `filter.*` rights sees only those. -->
<div class="modal-bg" id="filtersModal">
	<div class="modal modal-lg">
		<div class="modal-header">
			<h3><i class="fa-solid fa-filter"></i> <?= _h('panel.flt.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('filtersModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p style="color:var(--text-muted); font-size:0.87rem; margin:0 0 16px;"><?= _h('panel.flt.intro') ?></p>

			<!-- pt 4: what is being searched. Only the sections that make sense for the chosen
			     scope are rendered, so the modal opens at the size of one question rather than
			     showing every criterion of both objects at once. -->
			<?php /* "Wszystko" first, as in Moje pliki: this tab also has two lists, and showing
			         both is the state it is normally in. The buttons that need a permission are
			         hidden by showScopeButtons() rather than rendered dead. */ ?>
			<div class="flt-scope" id="fltScopeRow">
				<span class="detail-label"><?= _h('panel.flt.scope') ?></span>
				<div class="scope-picker">
					<button type="button" class="scope-btn active" data-scope="all" data-perm="collections.view_all" data-fh-click="setFilterScope('all')">
						<i class="fa-solid fa-layer-group"></i> <?= _h('panel.mflt.scope_all') ?>
					</button>
					<button type="button" class="scope-btn" data-scope="files" data-fh-click="setFilterScope('files')">
						<i class="fa-solid fa-file"></i> <?= _h('panel.flt.scope_files') ?>
					</button>
					<button type="button" class="scope-btn" data-scope="collections" data-perm="collections.view_all" data-fh-click="setFilterScope('collections')">
						<i class="fa-solid fa-box-archive"></i> <?= _h('panel.flt.scope_collections') ?>
					</button>
				</div>
				<small id="fltScopeHint"></small>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.date">
				<h4><i class="fa-solid fa-calendar"></i> <?= _h('panel.flt.date') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.top.from') ?></label><input type="date" id="fltDateFrom" class="input"></div>
					<div class="form-group"><label><?= _h('panel.top.to') ?></label><input type="date" id="fltDateTo" class="input"></div>
				</div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.size">
				<h4><i class="fa-solid fa-weight-scale"></i> <?= _h('panel.flt.size') ?></h4>
				<?= $sizeRange('flt') ?>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.downloads">
				<h4><i class="fa-solid fa-download"></i> <?= _h('panel.flt.downloads') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.flt.min') ?></label><input type="number" id="fltDlMin" class="input" min="0"></div>
					<div class="form-group"><label><?= _h('panel.flt.max') ?></label><input type="number" id="fltDlMax" class="input" min="0"></div>
				</div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.type">
				<h4><i class="fa-solid fa-file-code"></i> <?= _h('panel.flt.type') ?></h4>
				<!-- Not a .form-group: that class styles its inputs as full form fields, which
				     made this search box taller than the identical ones under Owner and IP. -->
				<div class="flt-field">
					<label><?= _h('panel.flt.extensions') ?></label>
					<input type="search" class="chip-search" id="fltExtListSearch" data-fh-input="filterChips('fltExtList')"
						placeholder="<?= _h('panel.flt.search_ph') ?>" autocomplete="off">
					<div class="chip-picker" id="fltExtList"></div>
				</div>
				<div class="form-group">
					<label><?= _h('panel.flt.mime') ?></label>
					<input type="text" id="fltMime" class="input" placeholder="<?= _h('panel.flt.mime_ph') ?>">
				</div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.user">
				<h4><i class="fa-solid fa-user"></i> <?= _h('panel.flt.user') ?></h4>
				<input type="search" class="chip-search" id="fltUserListSearch" data-fh-input="filterChips('fltUserList')"
					placeholder="<?= _h('panel.flt.search_ph') ?>" autocomplete="off">
				<div class="chip-picker" id="fltUserList"></div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.ip">
				<h4><i class="fa-solid fa-globe"></i> <?= _h('panel.flt.ip') ?></h4>
				<input type="search" class="chip-search" id="fltIpListSearch" data-fh-input="filterChips('fltIpList')"
					placeholder="<?= _h('panel.flt.search_ph') ?>" autocomplete="off">
				<div class="chip-picker" id="fltIpList"></div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.inactive">
				<h4><i class="fa-solid fa-hourglass-end"></i> <?= _h('panel.flt.inactive') ?></h4>
				<div class="form-group">
					<label><?= _h('panel.flt.inactive_days') ?></label>
					<input type="number" id="fltInactive" class="input" min="1" placeholder="<?= _h('panel.flt.inactive_ph') ?>">
					<small><?= _h('panel.flt.inactive_hint') ?></small>
				</div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.dead">
				<h4><i class="fa-solid fa-skull"></i> <?= _h('panel.flt.dead') ?></h4>
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" id="fltDead"><span><?= _h('panel.flt.dead_only') ?></span>
					</label>
					<small><?= _h('panel.flt.dead_hint') ?></small>
				</div>
			</div>

			<div class="flt-section" data-scope="all files" data-perm="filter.sharing">
				<h4><i class="fa-solid fa-share-nodes"></i> <?= _h('panel.flt.sharing') ?></h4>
				<div class="chip-picker" id="fltSharingList"></div>
			</div>

			<?php /* Membership as an ordinary criterion, the way "Moje pliki" has always had it.
			         It used to be a *scope* of its own, which put "pliki w kolekcjach" next to
			         "pliki" and "kolekcje" as if it were a third kind of thing to look at — it is
			         not, it is a question about files, and it belongs with the other questions
			         about files so it can be combined with them. */ ?>
			<div class="flt-section" data-scope="all files" data-perm="filter.in_collection">
				<h4><i class="fa-solid fa-box-archive"></i> <?= _h('panel.mflt.collection') ?></h4>
				<div class="chip-picker" id="fltCollList"></div>
				<small><?= _h('panel.mflt.collection_hint') ?></small>
			</div>

			<!-- ---- Collection criteria (pt 4). Shown only for the "collections" scope. ---- -->
			<div class="flt-section" data-scope="all collections" data-perm="cfilter.empty">
				<h4><i class="fa-solid fa-box-open"></i> <?= _h('panel.flt.c_empty') ?></h4>
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" id="fltCEmpty" data-fh-change="onEmptyCollectionsToggle()"><span><?= _h('panel.flt.c_empty_only') ?></span>
					</label>
					<small><?= _h('panel.flt.c_empty_hint') ?></small>
				</div>
			</div>

			<div class="flt-section" data-scope="all collections" data-perm="cfilter.files">
				<h4><i class="fa-solid fa-layer-group"></i> <?= _h('panel.flt.c_files') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.flt.min') ?></label><input type="number" id="fltCFilesMin" class="input" min="0"></div>
					<div class="form-group"><label><?= _h('panel.flt.max') ?></label><input type="number" id="fltCFilesMax" class="input" min="0"></div>
				</div>
			</div>

			<div class="flt-section" data-scope="all collections" data-perm="cfilter.size">
				<h4><i class="fa-solid fa-weight-scale"></i> <?= _h('panel.flt.c_size') ?></h4>
				<?= $sizeRange('fltC') ?>
			</div>

			<div class="flt-section" data-scope="all collections" data-perm="cfilter.date">
				<h4><i class="fa-solid fa-calendar"></i> <?= _h('panel.flt.date') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.top.from') ?></label><input type="date" id="fltCDateFrom" class="input"></div>
					<div class="form-group"><label><?= _h('panel.top.to') ?></label><input type="date" id="fltCDateTo" class="input"></div>
				</div>
			</div>

			<div class="flt-section" data-scope="all collections" data-perm="cfilter.downloads">
				<h4><i class="fa-solid fa-download"></i> <?= _h('panel.flt.downloads') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.flt.min') ?></label><input type="number" id="fltCDlMin" class="input" min="0"></div>
					<div class="form-group"><label><?= _h('panel.flt.max') ?></label><input type="number" id="fltCDlMax" class="input" min="0"></div>
				</div>
			</div>

			<div class="flt-section" data-scope="all collections" data-perm="cfilter.user">
				<h4><i class="fa-solid fa-user"></i> <?= _h('panel.flt.user') ?></h4>
				<input type="search" class="chip-search" id="fltCUserListSearch" data-fh-input="filterChips('fltCUserList')"
					placeholder="<?= _h('panel.flt.search_ph') ?>" autocomplete="off">
				<div class="chip-picker" id="fltCUserList"></div>
			</div>

			<div class="flt-section" data-scope="all collections" data-perm="cfilter.sharing">
				<h4><i class="fa-solid fa-share-nodes"></i> <?= _h('panel.flt.sharing') ?></h4>
				<div class="chip-picker" id="fltCSharingList"></div>
			</div>

			<div class="modal-btns">
				<button type="button" class="btn btn-danger" data-fh-click="clearAllFilters(true)"><i class="fa-solid fa-xmark"></i> <?= _h('panel.flt.clear_all') ?></button>
				<button type="button" class="btn" data-fh-click="closeModal('filtersModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="applyFilters()"><i class="fa-solid fa-check"></i> <?= _h('panel.flt.apply') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Collection settings (pt 17): rename + the same sharing controls a file has -->
<div class="modal-bg" id="collSettingsModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-gear"></i> <?= _h('panel.cc.edit_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('collSettingsModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="collSettingsMessage" class="auth-message"></div>
			<input type="hidden" id="csId">
			<div class="form-group">
				<label><?= _h('panel.cc.name') ?></label>
				<input type="text" id="csName" maxlength="255" placeholder="<?= _h('panel.cc.name_ph') ?>">
			</div>
			<div class="form-row">
				<div class="form-group">
					<label><?= _h('panel.fo.expiry') ?></label>
					<input type="number" id="csExpiry" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
					<small><?= _h('panel.fo.expiry_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.fo.max_dl') ?></label>
					<input type="number" id="csMaxDl" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.fo.on_limit') ?></label>
				<select id="csLimitAction" class="input">
					<option value="keep"><?= _h('panel.fo.on_limit_keep') ?></option>
					<option value="delete"><?= _h('panel.fo.on_limit_coll_delete') ?></option>
				</select>
				<small><?= _h('panel.fo.on_limit_coll_hint') ?></small>
			</div>
			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="csOneTime"><span><i class="fa-solid fa-fire" style="color:#f97316"></i> <?= _h('panel.fo.onetime') ?></span>
				</label>
				<small><?= _h('panel.fo.onetime_hint') ?></small>
			</div>
			<div class="form-group" id="csClearPwRow" style="display:none;">
				<label class="form-check">
					<input type="checkbox" id="csClearPw"><span><?= _h('panel.cc.pw_clear') ?></span>
				</label>
			</div>
			<div class="form-group">
				<label><?= _h('panel.cc.password') ?></label>
				<input type="password" id="csPassword" placeholder="<?= _h('panel.cc.pw_keep') ?>" minlength="8" maxlength="1024" autocomplete="new-password">
				<div class="pwd-meter"><div class="pwd-meter-fill" id="csPwdBar"></div></div>
				<ul class="pwd-reqs">
					<li id="csReqLen"><?= _h('pwd.req_len') ?></li>
					<li id="csReqUpper"><?= _h('pwd.req_upper') ?></li>
					<li id="csReqDigit"><?= _h('pwd.req_digit') ?></li>
					<li id="csReqSpec"><?= _h('pwd.req_special') ?></li>
				</ul>
				<input type="password" id="csPassword2" placeholder="<?= _h('pwd.repeat') ?>" maxlength="1024" style="margin-top:8px;" autocomplete="new-password">
				<div class="field-status" id="csPassMatch"></div>
			</div>
			<?php /* Runda 9: the member list — reorder with ↑/↓, take a file out with ×.
			         Changes apply immediately (each click is one API call), so the Save
			         button below stays about the settings fields only. */ ?>
			<div class="form-group">
				<label><i class="fa-solid fa-list-ol"></i> <?= _h('panel.cs.files_title') ?></label>
				<small><?= _h('panel.cs.files_hint') ?></small>
				<div id="csFilesList" class="cs-files-list"></div>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('collSettingsModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="saveCollectionSettings()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Password prompt for the quick-download button on a protected file row -->
<div class="modal-bg" id="fileDownloadModal">
	<div class="modal modal-sm">
		<div class="modal-header">
			<h3><i class="fa-solid fa-lock"></i> <?= _h('pwprompt.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('fileDownloadModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p style="color:var(--text-secondary); margin-bottom:14px;"><?= _h('pwprompt.subtitle') ?></p>
			<div id="fdError" class="auth-message"></div>
			<div class="form-group">
				<input type="password" id="fdPassword" maxlength="1024" placeholder="<?= _h('pwprompt.placeholder') ?>" autocomplete="current-password"
					data-fh-keydown="submitPanelOnEnter(event, 'fileDownload')">
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('fileDownloadModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="submitFileDownloadPassword()"><i class="fa-solid fa-download"></i> <?= _h('pwprompt.submit') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Password prompt before downloading a protected collection's ZIP (pt 14) -->
<div class="modal-bg" id="collZipModal">
	<div class="modal modal-md collection-zip-modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-lock"></i> <?= _h('panel.coll.zip_tooltip') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('collZipModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p id="czIntro" style="color:var(--text-secondary); margin-bottom:14px;"><?= _h('panel.cc.pw_required_download') ?></p>
			<div id="czError" class="auth-message"></div>
			<div class="form-group" id="czCollectionPasswordWrap">
				<input type="password" id="czPassword" maxlength="1024" placeholder="<?= _h('collection.password_ph') ?>" autocomplete="current-password"
					data-fh-keydown="submitPanelOnEnter(event, 'collectionDownload')">
			</div>
			<div id="czMemberPasswords" class="collection-password-list" hidden></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('collZipModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" id="czSubmitBtn" data-fh-click="submitCollectionZipPassword()"><i class="fa-solid fa-unlock"></i> <?= _h('collection.unlock') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Passwords for protected files being pulled into a collection (pt 7, and pt 1 for one's own
     files: a password on a file guards its contents, and a collection hands those out as a ZIP) -->
<div class="modal-bg" id="collLockedModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-lock"></i> <?= _h('panel.cc.locked_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('collLockedModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p style="color:var(--text-secondary); margin-bottom:14px;"><?= _h('panel.cc.locked_intro') ?></p>
			<div id="clLockedList"></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('collLockedModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="continueLockedFiles()"><?= _h('panel.cc.continue') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- "My files" filters (pt 8).
     Its own modal rather than a fourth scope of the admin one: that modal's scope also decides
     which of the Files tab's two tables is on screen, so borrowing it would have tied the two
     tabs' state together. The criteria are the ones that say something about one's own uploads
     — owner and IP are missing because they have a single answer here. Sections are rendered
     from the `mfilter.*` permissions the session holds, like every other filter panel. -->
<div class="modal-bg" id="myFiltersModal">
	<div class="modal modal-lg">
		<div class="modal-header">
			<h3><i class="fa-solid fa-filter"></i> <?= _h('panel.mflt.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('myFiltersModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p style="color:var(--text-muted); font-size:0.87rem; margin:0 0 16px;"><?= _h('panel.mflt.intro') ?></p>

			<?php /* pt 3: the same "what am I searching" question the all-files panel asks, with
			         a third answer this tab needs and that one does not: "both". The Moje pliki
			         tab shows files *and* collections one under the other, so "wszystko" is the
			         state it is normally in — the other two hide the list you are not looking
			         for, and offer only the criteria that make sense for the one you are. */ ?>
			<div class="flt-scope" id="mfltScopeRow">
				<span class="detail-label"><?= _h('panel.flt.scope') ?></span>
				<div class="scope-picker">
					<button type="button" class="scope-btn active" data-scope="all" data-fh-click="setMyFilterScope('all')">
						<i class="fa-solid fa-layer-group"></i> <?= _h('panel.mflt.scope_all') ?>
					</button>
					<button type="button" class="scope-btn" data-scope="files" data-fh-click="setMyFilterScope('files')">
						<i class="fa-solid fa-file"></i> <?= _h('panel.flt.scope_files') ?>
					</button>
					<button type="button" class="scope-btn" data-scope="collections" data-fh-click="setMyFilterScope('collections')">
						<i class="fa-solid fa-box-archive"></i> <?= _h('panel.flt.scope_collections') ?>
					</button>
				</div>
				<small id="mfltScopeHint"></small>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.date">
				<h4><i class="fa-solid fa-calendar"></i> <?= _h('panel.flt.date') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.top.from') ?></label><input type="date" id="mfltDateFrom" class="input"></div>
					<div class="form-group"><label><?= _h('panel.top.to') ?></label><input type="date" id="mfltDateTo" class="input"></div>
				</div>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.size">
				<h4><i class="fa-solid fa-weight-scale"></i> <?= _h('panel.flt.size') ?></h4>
				<?= $sizeRange('mflt') ?>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.downloads">
				<h4><i class="fa-solid fa-download"></i> <?= _h('panel.flt.downloads') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.flt.min') ?></label><input type="number" id="mfltDlMin" class="input" min="0"></div>
					<div class="form-group"><label><?= _h('panel.flt.max') ?></label><input type="number" id="mfltDlMax" class="input" min="0"></div>
				</div>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.type">
				<h4><i class="fa-solid fa-file-code"></i> <?= _h('panel.flt.type') ?></h4>
				<div class="flt-field">
					<label><?= _h('panel.flt.extensions') ?></label>
					<input type="search" class="chip-search" id="mfltExtListSearch" data-fh-input="filterChips('mfltExtList')"
						placeholder="<?= _h('panel.flt.search_ph') ?>" autocomplete="off">
					<div class="chip-picker" id="mfltExtList"></div>
				</div>
				<div class="form-group">
					<label><?= _h('panel.flt.mime') ?></label>
					<input type="text" id="mfltMime" class="input" placeholder="<?= _h('panel.flt.mime_ph') ?>">
				</div>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.sharing">
				<h4><i class="fa-solid fa-share-nodes"></i> <?= _h('panel.flt.sharing') ?></h4>
				<div class="chip-picker" id="mfltSharingList"></div>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.in_collection">
				<h4><i class="fa-solid fa-box-archive"></i> <?= _h('panel.mflt.collection') ?></h4>
				<div class="chip-picker" id="mfltCollList"></div>
				<small><?= _h('panel.mflt.collection_hint') ?></small>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.inactive">
				<h4><i class="fa-solid fa-hourglass-end"></i> <?= _h('panel.flt.inactive') ?></h4>
				<div class="form-group">
					<label><?= _h('panel.flt.inactive_days') ?></label>
					<input type="number" id="mfltInactive" class="input" min="1" placeholder="<?= _h('panel.flt.inactive_ph') ?>">
					<small><?= _h('panel.mflt.inactive_hint') ?></small>
				</div>
			</div>

			<div class="flt-section" data-mscope="all files" data-perm="mfilter.dead">
				<h4><i class="fa-solid fa-skull"></i> <?= _h('panel.flt.dead') ?></h4>
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" id="mfltDead"><span><?= _h('panel.flt.dead_only') ?></span>
					</label>
					<small><?= _h('panel.mflt.dead_hint') ?></small>
				</div>
			</div>

			<?php /* pt 4: the own-collections criteria, each behind its own `mcfilter.*` — the
			         same shape the all-collections panel has, asked about your own. */ ?>
			<div class="flt-section" data-mscope="all collections" data-perm="mcfilter.empty">
				<h4><i class="fa-solid fa-box-open"></i> <?= _h('panel.flt.c_empty') ?></h4>
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" id="mfltCEmpty" data-fh-change="onMyEmptyCollectionsToggle()"><span><?= _h('panel.flt.c_empty_only') ?></span>
					</label>
					<small><?= _h('panel.flt.c_empty_hint') ?></small>
				</div>
			</div>

			<div class="flt-section" data-mscope="all collections" data-perm="mcfilter.files">
				<h4><i class="fa-solid fa-layer-group"></i> <?= _h('panel.flt.c_files') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.flt.min') ?></label><input type="number" id="mfltCFilesMin" class="input" min="0"></div>
					<div class="form-group"><label><?= _h('panel.flt.max') ?></label><input type="number" id="mfltCFilesMax" class="input" min="0"></div>
				</div>
			</div>

			<div class="flt-section" data-mscope="all collections" data-perm="mcfilter.size">
				<h4><i class="fa-solid fa-weight-scale"></i> <?= _h('panel.flt.c_size') ?></h4>
				<?= $sizeRange('mfltC') ?>
			</div>

			<div class="flt-section" data-mscope="all collections" data-perm="mcfilter.date">
				<h4><i class="fa-solid fa-calendar"></i> <?= _h('panel.flt.date') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.top.from') ?></label><input type="date" id="mfltCDateFrom" class="input"></div>
					<div class="form-group"><label><?= _h('panel.top.to') ?></label><input type="date" id="mfltCDateTo" class="input"></div>
				</div>
			</div>

			<div class="flt-section" data-mscope="all collections" data-perm="mcfilter.downloads">
				<h4><i class="fa-solid fa-download"></i> <?= _h('panel.flt.downloads') ?></h4>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.flt.min') ?></label><input type="number" id="mfltCDlMin" class="input" min="0"></div>
					<div class="form-group"><label><?= _h('panel.flt.max') ?></label><input type="number" id="mfltCDlMax" class="input" min="0"></div>
				</div>
			</div>

			<div class="flt-section" data-mscope="all collections" data-perm="mcfilter.sharing">
				<h4><i class="fa-solid fa-share-nodes"></i> <?= _h('panel.flt.sharing') ?></h4>
				<div class="chip-picker" id="mfltCSharingList"></div>
			</div>

			<div class="modal-btns">
				<button type="button" class="btn btn-danger" data-fh-click="clearAllMyFilters(true)"><i class="fa-solid fa-xmark"></i> <?= _h('panel.flt.clear_all') ?></button>
				<button type="button" class="btn" data-fh-click="closeModal('myFiltersModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="applyMyFilters()"><i class="fa-solid fa-check"></i> <?= _h('panel.flt.apply') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- pt 5: file an existing file into an existing collection.
     Opens from the + on a file row. The list is the caller's own collections, most recent
     first, with a search box for when there are more than a handful — and a password field
     that only appears for a locked file, because the ZIP would hand its contents over. -->
<div class="modal-bg" id="addToCollectionModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-folder-plus"></i> <?= _h('panel.atc.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('addToCollectionModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="addToCollectionMessage" class="auth-message"></div>
			<p style="color:var(--text-secondary); margin:0 0 14px;">
				<?= __('panel.atc.intro', ['name' => $slot('atcFileName')]) ?>
			</p>

			<div id="atcPicker">
				<input type="search" class="chip-search" id="atcSearch" autocomplete="off"
					placeholder="<?= _h('panel.atc.search_ph') ?>" data-fh-input="renderAddToCollectionList()">
				<small id="atcHint" style="display:block; margin:8px 0 6px; color:var(--text-muted);"></small>
				<div class="atc-list" id="atcList"></div>
			</div>

			<div class="form-group" id="atcPasswordRow" style="display:none; margin-top:14px;">
				<label><?= _h('panel.cc.locked_ph') ?></label>
				<input type="password" id="atcPassword" maxlength="1024" autocomplete="off" placeholder="<?= _h('panel.cc.locked_ph') ?>">
				<small><?= _h('panel.atc.password_hint') ?></small>
			</div>

			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('addToCollectionModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary is-inactive" id="atcSubmit" data-fh-click="submitAddToCollection()">
					<i class="fa-solid fa-check"></i> <?= _h('panel.atc.add') ?>
				</button>
			</div>
		</div>
	</div>
</div>

<!-- Delete Collection Modal (Faza 3.2) -->
<div class="modal-bg" id="deleteCollectionModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trash"></i> <?= _h('panel.cc.del_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('deleteCollectionModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="delCollId">
			<p><?= __('panel.cc.del_q', ['name' => $slot('delCollName')]) ?></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('deleteCollectionModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="confirmDeleteCollection()"><?= _h('common.delete') ?></button>
			</div>
		</div>
	</div>
</div>
