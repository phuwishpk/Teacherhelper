import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/widgets/content_column.dart';
import 'practice_models.dart';

/// A review link (DESIGN §14.1 "ลิงก์ทบทวน"). Tapping copies the address,
/// like the other outside links of the app, to open in the browser.
class ResourceLinkTile extends StatelessWidget {
  const ResourceLinkTile({super.key, required this.resource, this.trailing});

  final LearningResource resource;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.menu_book_outlined),
      title: Text(resource.title.isEmpty ? resource.url : resource.title),
      subtitle: Text(
        resource.url,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: trailing ?? const Icon(Icons.copy, size: 18),
      onTap: () async {
        await Clipboard.setData(ClipboardData(text: resource.url));
        if (context.mounted) {
          showMessage(context, 'คัดลอกลิงก์แล้ว วางในเบราว์เซอร์เพื่อเปิด');
        }
      },
    );
  }
}
