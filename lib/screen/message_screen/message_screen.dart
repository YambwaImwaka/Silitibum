import 'package:flutter/material.dart';
import 'package:shortzz/common/extensions/page_controller_extension.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/common/widget/custom_search_text_field.dart';
import 'package:shortzz/common/widget/custom_tab_switcher.dart';
import 'package:shortzz/common/widget/error_retry_view.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/no_data_widget.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/message_screen/message_screen_controller.dart';
import 'package:shortzz/screen/message_screen/widget/chat_conversation_user_card.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Messages as its own screen, opened from the profile (it used to be a
/// bottom-bar tab).
class MessagesPage extends StatelessWidget {
  const MessagesPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: scaffoldBackgroundColor(context),
      body: const MessageScreen(showBack: true),
    );
  }
}

class MessageScreen extends StatelessWidget {
  final bool showBack;

  const MessageScreen({super.key, this.showBack = false});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(MessageScreenController());
    return Column(
      children: [
        Container(
          color: scaffoldBackgroundColor(context),
          child: SafeArea(
            minimum: const EdgeInsets.only(top: 15),
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 10.0),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      if (showBack)
                        Align(
                          alignment: AlignmentDirectional.centerStart,
                          child: CustomBackButton(
                              color: textDarkGrey(context),
                              height: 26,
                              width: 26,
                              padding: const EdgeInsets.only(left: 12)),
                        ),
                      Text(LKey.messages.tr,
                          style: TextStyleCustom.unboundedMedium500(
                              fontSize: 15, color: textDarkGrey(context))),
                    ],
                  ),
                ),
                CustomTabSwitcher(
                  items: controller.chatCategories,
                  onTap: (index) {
                    controller.onPageChanged(index);
                    controller.pageController.animateToPageIfAttached(index,
                        duration: const Duration(milliseconds: 300),
                        curve: Curves.linear);
                  },
                  selectedIndex: controller.selectedChatCategory,
                  widget: Obx(() {
                    int length = controller.dashboardController.requestUnReadCount.value;
                    if (length <= 0) {
                      return const SizedBox();
                    }
                    return Container(
                      height: 22,
                      width: 22,
                      margin: const EdgeInsets.symmetric(horizontal: 5),
                      decoration: const BoxDecoration(
                          shape: BoxShape.circle, color: ColorRes.likeRed),
                      alignment: Alignment.center,
                      child: Text(
                        '$length',
                        style: TextStyleCustom.outFitRegular400(
                            fontSize: 12, color: whitePure(context)),
                      ),
                    );
                  }),
                  widgetTabIndex: 1,
                  margin: const EdgeInsets.all(10),
                ),
              ],
            ),
          ),
        ),
        CustomSearchTextField(
          controller: controller.searchController,
          onChanged: controller.onSearchChanged,
        ),
        Expanded(
          child: Obx(() {
            final bool isEmpty = controller.selectedChatCategory.value == 0
                ? controller.chatsUsers.isEmpty
                : controller.requestsUsers.isEmpty;
            if (controller.isThreadsLoading.value && isEmpty) {
              return const LoaderWidget();
            }
            // A failed fetch used to render as "no chats yet", which reads as
            // an empty inbox rather than an error the user can retry.
            final String? error = controller.threadsError.value;
            if (error != null && isEmpty) {
              return ErrorRetryView(
                  message: error, onRetry: controller.retryFetchThreads);
            }
            return PageView(
              controller: controller.pageController,
              onPageChanged: controller.onPageChanged,
              children: const [
                ChatsListView(),
                RequestsListView(),
              ],
            );
          }),
        )
      ],
    );
  }
}

class ChatsListView extends StatelessWidget {
  const ChatsListView({super.key});

  @override
  Widget build(BuildContext context) {
    final MessageScreenController controller = Get.find();
    return Obx(() {
      final threads = controller.visibleThreads(requests: false);
      return NoDataView(
        showShow: threads.isEmpty,
        title: LKey.chatListEmptyTitle.tr,
        description: LKey.chatListEmptyDescription.tr,
        child: ListView.builder(
          itemCount: threads.length,
          padding: EdgeInsets.zero,
          itemBuilder: (context, index) {
            // Binding happens once in the controller's _upsertThread, not here
            // — this runs on every rebuild and scroll frame.
            return ChatConversationUserCard(chatConversation: threads[index]);
          },
        ),
      );
    });
  }
}

class RequestsListView extends StatelessWidget {
  const RequestsListView({super.key});

  @override
  Widget build(BuildContext context) {
    final MessageScreenController controller = Get.find();

    return Obx(() {
      final threads = controller.visibleThreads(requests: true);
      return NoDataView(
        showShow: threads.isEmpty,
        title: LKey.chatRequestEmptyTitle.tr,
        description: LKey.chatRequestEmptyDescription.tr,
        child: ListView.builder(
          itemCount: threads.length,
          padding: EdgeInsets.zero,
          itemBuilder: (context, index) {
            return ChatConversationUserCard(chatConversation: threads[index]);
          },
        ),
      );
    });
  }
}
