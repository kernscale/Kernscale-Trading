#import <Foundation/Foundation.h>
#import <PDFKit/PDFKit.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>

static int errorFD = STDERR_FILENO;

static int reject(void) {
    dprintf(errorFD, "PDF text extraction failed.\n");
    return 1;
}

int main(int argc, const char *argv[]) {
    @autoreleasepool {
        if (argc != 7 || strcmp(argv[1], "-layout") || strcmp(argv[2], "-enc") ||
            strcmp(argv[3], "UTF-8") || strcmp(argv[4], "-nopgbrk") || strcmp(argv[6], "-")) {
            return reject();
        }
        // Keep native parser diagnostics out of attachment responses and application logs.
        int savedError = dup(STDERR_FILENO);
        int silent = open("/dev/null", O_WRONLY);
        if (savedError < 0 || silent < 0) return reject();
        errorFD = savedError;
        if (dup2(silent, STDERR_FILENO) < 0) return reject();
        close(silent);
        @try {
            NSString *path = [NSString stringWithUTF8String:argv[5]];
            if (!path) return reject();
            PDFDocument *document = [[PDFDocument alloc] initWithURL:[NSURL fileURLWithPath:path]];
            if (!document || document.isEncrypted || document.isLocked || document.pageCount == 0) {
                return reject();
            }
            NSMutableData *output = [NSMutableData data];
            NSCharacterSet *whitespace = [NSCharacterSet whitespaceAndNewlineCharacterSet];
            BOOL hasText = NO;
            for (NSUInteger index = 0; index < document.pageCount; index++) {
                NSString *text = [document pageAtIndex:index].string ?: @"";
                hasText |= [text stringByTrimmingCharactersInSet:whitespace].length > 0;
                NSData *bytes = [[text stringByAppendingString:@"\n"] dataUsingEncoding:NSUTF8StringEncoding];
                if (!bytes || bytes.length > 2097152 || output.length > 2097152 - bytes.length) {
                    return reject();
                }
                [output appendData:bytes];
            }
            if (!hasText) return reject();
            if (fwrite(output.bytes, 1, output.length, stdout) != output.length) return reject();
            return 0;
        } @catch (NSException *exception) {
            return reject();
        }
    }
}
