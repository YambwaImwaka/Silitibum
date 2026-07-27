import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/material.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/functions/debounce_action.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/post_story/music/music_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/selected_music_sheet/selected_music_sheet.dart';
import 'package:shortzz/screen/selected_music_sheet/selected_music_sheet_controller.dart';

class MusicSheetController extends BaseController {
  List<String> categories = [LKey.explore.tr, LKey.categories.tr, LKey.saved.tr];
  RxInt selectedMusicCategory = 0.obs;
  int videoSecond;
  RxBool isMusicDownloading = false.obs;

  MusicSheetController(this.videoSecond);

  PageController pageController = PageController();

  RxList<Music> exploreMusicList = <Music>[].obs;
  RxList<MusicCategory> musicCategoryList = <MusicCategory>[].obs;
  RxList<Music> savedMusicList = <Music>[].obs;
  RxList<Music> categoryMusicList = <Music>[].obs;
  RxList<Music> searchMusicList = <Music>[].obs;
  RxList<int> savedMusicIds = <int>[].obs;

  RxBool isSearch = false.obs;

  TextEditingController searchController = TextEditingController(text: '');

  @override
  void onInit() {
    super.onInit();
    initData();
  }

  initData() {
    fetchMusicExplore();
    fetchMusicCategories();
    fetchSavedMusics();
    getUserData();
  }

  getUserData() {
    User? user = SessionManager.instance.getUser();
    savedMusicIds.value = user?.savedMusicIds == null
        ? []
        : (user?.savedMusicIds ?? '').split(',').map((e) {
            return int.parse(e);
          }).toList();
  }

  onChangedMusicCategories(int index) {
    selectedMusicCategory.value = index;
    pageController.animateToPage(index,
        duration: const Duration(milliseconds: 250), curve: Curves.easeIn);

    // search field cancel
    isSearch.value = false;
    searchController.clear();

    switch (index) {
      case 0:
        fetchMusicExplore(isEmpty: true);
        break;
      case 1:
        fetchMusicCategories();
        break;
      case 2:
        fetchSavedMusics(isEmpty: true);
        break;
    }
  }

  fetchMusicCategories() {
    Setting? setting = SessionManager.instance.getSettings();
    musicCategoryList.value = setting?.musicCategories ?? [];
  }

  /// FIX: every fetch below used to clear [isLoading] only on its success
  /// path. When the awaited call threw — a 30s `Request timed out` against a
  /// cold-starting server was enough — the trailing `isLoading.value = false`
  /// never ran, so `MusicList` rendered its LoaderWidget forever with no
  /// error and no way to retry. The methods are `async void`, so the
  /// exception escaped into nothing and (with Crashlytics off in debug) left
  /// no trace at all. Clearing the flag in a `finally` guarantees the sheet
  /// always settles into either content or an empty state.
  void _onFetchFailed(String action, Object e, StackTrace st) {
    Loggers.error('$action failed: $e');
    FirebaseCrashlytics.instance.recordError(e, st, fatal: false);
    // showSnackBar() no-ops while one is already open, so the three fetches
    // initData() kicks off concurrently surface a single message, not three.
    showSnackBar(LKey.somethingWentWrong.tr);
  }

  void fetchMusicExplore({bool isEmpty = false}) async {
    isLoading.value = true;
    try {
      int? lastItemId = isEmpty || exploreMusicList.isEmpty ? null : exploreMusicList.last.id;
      List<Music> items =
          await PostService.instance.fetchMusicExplore(lastItemId: lastItemId);
      if (isEmpty) {
        exploreMusicList.clear();
      }
      exploreMusicList.addAll(items);
    } catch (e, st) {
      _onFetchFailed('fetchMusicExplore', e, st);
    } finally {
      isLoading.value = false;
    }
  }

  void fetchMusicByCategories(int? categoryId, {bool isEmpty = false}) async {
    int id = categoryId ?? -1;
    if (categoryId == -1) {
      return Loggers.error('Invalid Id : $id');
    }
    isLoading.value = true;
    try {
      int? lastItemId = isEmpty || categoryMusicList.isEmpty ? null : categoryMusicList.last.id;
      List<Music> items = await PostService.instance
          .fetchMusicByCategories(lastItemId: lastItemId, categoryId: id);
      if (isEmpty) {
        categoryMusicList.clear();
      }
      categoryMusicList.addAll(items);
    } catch (e, st) {
      _onFetchFailed('fetchMusicByCategories', e, st);
    } finally {
      isLoading.value = false;
    }
  }

  void fetchSavedMusics({bool isEmpty = false}) async {
    isLoading.value = true;
    try {
      List<Music> items = await PostService.instance.fetchSavedMusics();
      if (isEmpty) {
        savedMusicList.clear();
      }
      savedMusicList.addAll(items);
    } catch (e, st) {
      _onFetchFailed('fetchSavedMusics', e, st);
    } finally {
      isLoading.value = false;
    }
  }

  void searchMusic(String keyword, {bool isEmpty = false}) async {
    // Callers (onChanged/onSearchTap) own setting isLoading here; this method
    // only guarantees it comes back down.
    try {
      List<Music> items = await PostService.instance.searchMusic(
        keyword: keyword,
        lastItemId: searchMusicList.isEmpty || isEmpty ? null : searchMusicList.last.id,
      );

      if (isEmpty) {
        searchMusicList.clear();
      }
      searchMusicList.addAll(items);
    } catch (e, st) {
      _onFetchFailed('searchMusic', e, st);
    } finally {
      isLoading.value = false;
    }
  }

  void onSearchTap() {
    isSearch.value = true;
    searchController.clear();
    searchMusic(searchController.text.trim(), isEmpty: true);
  }

  onChanged(String _) {
    if (searchController.text.trim().isEmpty) {
      searchMusicList.clear();
    }
    isLoading.value = true;
    DebounceAction.shared.call(() {
      searchMusic(searchController.text.trim(), isEmpty: true);
    });
  }

  void updateSavedMusicIds(List<int> savedMusicIds) async {
    await UserService.instance.updateUserDetails(savedMusicIds: savedMusicIds);
    this.savedMusicIds.value =
        (SessionManager.instance.getUser()?.savedMusicIds ?? '')
            .split(',')
        .map((e) => int.parse(e))
        .toList();
  }

  void onCancelTap() {
    isSearch.value = false;
    searchController.clear();
  }

  void onBookMarkTap(Music music) {
    if (savedMusicIds.contains(music.id)) {
      savedMusicIds.remove(music.id);
    } else {
      savedMusicIds.add(music.id!);
    }
    updateSavedMusicIds(savedMusicIds);
  }

  void onTapMusic(Music music, bool isCategorySheet) async {
    if (isCategorySheet) {
      Get.back();
    }

    if (isMusicDownloading.value) return;
    isMusicDownloading.value = true;
    String downloadMusicPath = (await DefaultCacheManager()
            .getSingleFile(music.sound?.addBaseURL() ?? ''))
        .path;
    isMusicDownloading.value = false;
    if (downloadMusicPath.isNotEmpty) {
      SelectedMusic? selectedMusic = await Get.bottomSheet<SelectedMusic>(
          SelectedMusicSheet(
              selectedMusic: SelectedMusic(music, 0, downloadMusicPath, 0),
              totalVideoSecond: videoSecond),
          enableDrag: false,
          isScrollControlled: true);
      if (selectedMusic != null) {
        Get.back(result: selectedMusic);
      }
    }
  }

  void onTapOutside(PointerDownEvent event) {
    isSearch.value = false;
  }
}
